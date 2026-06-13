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
 * 1. If the uploaded bytes (hash + size + original filename) match an already
 *    registered file AND the target product/product model value already points at that
 *    key, the request becomes a complete no-op: no new file, no file_info row, no
 *    product update, no save, no reindex. 201 + Location of the existing key.
 * 2. If the bytes match an existing file but the value points elsewhere, the existing
 *    key is reused (no new physical copy) and only the product value is updated.
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

        $reused = $this->resolveReusableUpload($request->files);
        if (null === $reused) {
            return parent::createProductMedia($request);
        }

        if ($this->currentValueKey($product, $productInfos) !== $reused->getKey()) {
            $this->linkReusedFile(fn () => $this->linkFileToProduct($reused, $product, $productInfos));
        }
        // else: byte-identical to the value already in place — skip update and save entirely

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

        $reused = $this->resolveReusableUpload($request->files);
        if (null === $reused) {
            return parent::createProductModelMedia($request);
        }

        if ($this->currentValueKey($productModel, $productModelInfos) !== $reused->getKey()) {
            $this->linkReusedFile(fn () => $this->linkFileToProductModel($reused, $productModel, $productModelInfos));
        }

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
     * @param array{attribute: string, locale: ?string, scope: ?string} $infos
     */
    private function currentValueKey(EntityWithValuesInterface $entity, array $infos): ?string
    {
        try {
            $value = $entity->getValue($infos['attribute'], $infos['locale'], $infos['scope']);
        } catch (\Exception $e) {
            // Unknown attribute, wrong locale/scope...: let the stock updater produce
            // the canonical error by not short-circuiting.
            return null;
        }

        $data = null === $value ? null : $value->getData();

        return $data instanceof FileInfoInterface ? $data->getKey() : null;
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
