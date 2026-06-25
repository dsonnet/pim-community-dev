<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Controller\ExternalApi;

use Akeneo\Pim\Enrichment\Bundle\Controller\ExternalApi\MediaFileController;
use Akeneo\Pim\Enrichment\Component\FileStorage;
use Akeneo\Pim\Enrichment\Component\Product\Model\EntityWithValuesInterface;
use Akeneo\Tool\Component\Api\Exception\ViolationHttpException;
use Akeneo\Tool\Component\FileStorage\Model\FileInfoInterface;
use Akeneo\Tool\Component\StorageUtils\Remover\RemoverInterface;
use Cld\Bundle\FileStorageCleanupBundle\Service\ReusableFileResolver;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Router;

/**
 * Drop-in replacement for the external API media-files controller
 * (POST /api/rest/v1/media-files) that makes re-uploading identical images idempotent:
 *
 * 1. If the value already in place references a byte-identical file that is still on
 *    disk (same hash + size + original filename, regardless of which key holds those
 *    bytes), the request is a complete no-op: no new file, no file_info row, no product
 *    update, no save, no reindex, no new history version. 201 + Location of that key.
 *    Matching on content rather than key string is essential: pre-dedup, the same image
 *    was stored under a fresh key per upload, so the value's key rarely equals the
 *    canonical reuse key — a key comparison would re-link (and churn) an already-correct
 *    value, orphaning its current key.
 * 2. If the bytes match an existing file but the value does NOT already hold them, the
 *    existing key is reused (no new physical copy) and only the product value is updated.
 * 3. Otherwise the stock behavior runs unchanged.
 *
 * In case 2 the stock failure path would be harmful: the parent controller removes the
 * FileInfo when the product update or validation fails — fine for a freshly stored
 * file, but a REUSED FileInfo may be referenced by other products, so removing its row
 * would deregister a live image. The remover is therefore swapped for a no-op while
 * linking a reused file.
 *
 * Activated by setting cld_file_storage_cleanup.enable_api_media_dedup to true, which
 * swaps the pim_api.controller.media_file service to this class (see
 * ApiMediaDedupPass). The resolver is injected by setter to avoid re-declaring the
 * parent's 19 constructor arguments.
 */
class DedupMediaFileController extends MediaFileController
{
    private ReusableFileResolver $reusableFileResolver;

    public function setReusableFileResolver(ReusableFileResolver $resolver): void
    {
        $this->reusableFileResolver = $resolver;
    }

    protected function createProductMedia(Request $request): Response
    {
        $productInfos = $this->getProductDecodedContent($request->request->get('product'));
        $product = $this->productRepository->findOneByIdentifier($productInfos['identifier']);
        if (null === $product) {
            throw new UnprocessableEntityHttpException(
                sprintf('Product "%s" does not exist.', $productInfos['identifier'])
            );
        }

        $current = $this->currentValueFileInfo($product, $productInfos);
        if (null !== $current && $this->valueAlreadyHolds($current, $request->files)) {
            // Byte-identical to the value already in place: change nothing at all.
            return $this->createdResponse($current);
        }

        $reused = $this->resolveReusableUpload($request->files);
        if (null === $reused) {
            return parent::createProductMedia($request);
        }

        $this->linkReusedFile(fn () => $this->linkFileToProduct($reused, $product, $productInfos));

        return $this->createdResponse($reused);
    }

    protected function createProductModelMedia(Request $request): Response
    {
        $productModelInfos = $this->getProductModelDecodedContent($request->request->get('product_model'));
        $productModel = $this->productModelRepository->findOneByIdentifier($productModelInfos['code']);
        if (null === $productModel) {
            throw new UnprocessableEntityHttpException(
                sprintf('Product model "%s" does not exist.', $productModelInfos['code'])
            );
        }

        $current = $this->currentValueFileInfo($productModel, $productModelInfos);
        if (null !== $current && $this->valueAlreadyHolds($current, $request->files)) {
            return $this->createdResponse($current);
        }

        $reused = $this->resolveReusableUpload($request->files);
        if (null === $reused) {
            return parent::createProductModelMedia($request);
        }

        $this->linkReusedFile(fn () => $this->linkFileToProductModel($reused, $productModel, $productModelInfos));

        return $this->createdResponse($reused);
    }

    private function resolveReusableUpload(FileBag $files): ?FileInfoInterface
    {
        if (!$files->has('file')) {
            // Parent raises the canonical "Property "file" is required." error.
            return null;
        }

        $fileInfo = $this->reusableFileResolver->resolve($files->get('file'), FileStorage::CATALOG_STORAGE_ALIAS);
        if (null === $fileInfo) {
            return null;
        }

        // Same gate a fresh upload goes through (allowed extensions/mime can have been
        // tightened since the original file was registered).
        $violations = $this->validator->validate($fileInfo);
        if ($violations->count() > 0) {
            throw new ViolationHttpException($violations);
        }

        return $fileInfo;
    }

    /**
     * The FileInfo a media value currently holds, or null if the value is empty or the
     * attribute/locale/scope is wrong (in which case we don't short-circuit and let the
     * stock updater produce the canonical error).
     *
     * @param array{attribute: string, locale: ?string, scope: ?string} $infos
     */
    private function currentValueFileInfo(EntityWithValuesInterface $entity, array $infos): ?FileInfoInterface
    {
        try {
            $value = $entity->getValue($infos['attribute'], $infos['locale'], $infos['scope']);
        } catch (\Exception $e) {
            return null;
        }

        $data = null === $value ? null : $value->getData();

        return $data instanceof FileInfoInterface ? $data : null;
    }

    /**
     * Whether the value already in place is a byte-identical, still-on-disk copy of the
     * uploaded file — the signal that the re-upload must leave the attribute untouched.
     */
    private function valueAlreadyHolds(FileInfoInterface $current, FileBag $files): bool
    {
        $upload = $files->has('file') ? $files->get('file') : null;

        return $upload instanceof \SplFileInfo
            && $this->reusableFileResolver->matchesExisting($current, $upload);
    }

    /**
     * Run a linkFileTo*() call with the FileInfo remover disabled: the parent removes
     * the FileInfo when the update/validation fails, which must never happen to a
     * reused (potentially shared, live) FileInfo.
     */
    private function linkReusedFile(callable $link): void
    {
        $originalRemover = $this->remover;
        $this->remover = new class() implements RemoverInterface {
            public function remove($object, array $options = []): void
            {
            }
        };

        try {
            $link();
        } finally {
            $this->remover = $originalRemover;
        }
    }

    private function createdResponse(FileInfoInterface $fileInfo): Response
    {
        $response = new Response(null, Response::HTTP_CREATED);
        $route = $this->router->generate(
            'pim_api_media_file_get',
            ['code' => $fileInfo->getKey()],
            Router::ABSOLUTE_URL
        );
        $response->headers->set('Location', $route);

        return $response;
    }
}
