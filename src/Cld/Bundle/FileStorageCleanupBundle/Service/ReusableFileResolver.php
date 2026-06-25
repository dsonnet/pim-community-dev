<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

use Akeneo\Tool\Component\FileStorage\FilesystemProvider;
use Akeneo\Tool\Component\FileStorage\Model\FileInfoInterface;
use Akeneo\Tool\Component\FileStorage\Repository\FileInfoRepositoryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Resolves a local file to an already-registered, byte-identical FileInfo, so callers
 * can reuse the existing file_key instead of storing a new physical copy.
 *
 * Matching rule: same content sha1 (the hash column Akeneo computes at upload time),
 * same size, same original filename, same storage, and the physical file must still
 * exist. The filename must match because the stored key embeds it and the UI exposes
 * it as the download name — reusing content under a different name would leak the
 * other upload's filename to users.
 */
class ReusableFileResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FileInfoRepositoryInterface $repository,
        private readonly FilesystemProvider $filesystemProvider,
    ) {
    }

    public function resolve(\SplFileInfo $rawFile, string $storageAlias): ?FileInfoInterface
    {
        $identity = $this->identity($rawFile);
        if (null === $identity) {
            return null;
        }

        $candidateKeys = $this->connection->fetchFirstColumn(
            'SELECT file_key FROM akeneo_file_storage_file_info
             WHERE storage = ? AND hash = ? AND size = ? AND original_filename = ?
             ORDER BY id ASC LIMIT 10',
            [$storageAlias, $identity['hash'], $identity['size'], $identity['original_filename']]
        );

        if ([] === $candidateKeys) {
            return null;
        }

        $filesystem = $this->filesystemProvider->getFilesystem($storageAlias);
        foreach ($candidateKeys as $key) {
            try {
                if (!$filesystem->fileExists((string) $key)) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }

            $fileInfo = $this->repository->findOneByIdentifier((string) $key);
            if (null !== $fileInfo) {
                return $fileInfo;
            }
        }

        return null;
    }

    /**
     * Whether an already-registered $fileInfo is a still-intact, byte-identical copy of
     * $rawFile: same content identity (hash + size + original filename — the same rule
     * resolve() matches on) AND the physical file still present on disk.
     *
     * Lets a caller detect that a value already points at this exact image, so a
     * re-upload leaves the attribute untouched instead of re-linking it to another key
     * holding the same bytes — which would churn the value, orphan the current key, and
     * show up in the product history. The on-disk check means a value whose file has
     * gone missing is NOT treated as a match, so a re-upload can heal it.
     */
    public function matchesExisting(FileInfoInterface $fileInfo, \SplFileInfo $rawFile): bool
    {
        $identity = $this->identity($rawFile);
        if (null === $identity) {
            return false;
        }

        if ($fileInfo->getHash() !== $identity['hash']
            || (int) $fileInfo->getSize() !== $identity['size']
            || $fileInfo->getOriginalFilename() !== $identity['original_filename']) {
            return false;
        }

        try {
            return $this->filesystemProvider
                ->getFilesystem($fileInfo->getStorage())
                ->fileExists($fileInfo->getKey());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Content identity of a local raw file: the sha1 Akeneo stores as the hash column,
     * the byte size, and the client/original filename (the stored key embeds it).
     *
     * @return array{hash: string, size: int, original_filename: string}|null
     */
    private function identity(\SplFileInfo $rawFile): ?array
    {
        $path = $rawFile->getPathname();
        if (!is_file($path)) {
            return null;
        }

        $hash = @sha1_file($path);
        $size = @filesize($path);
        if (false === $hash || false === $size) {
            return null;
        }

        return [
            'hash' => $hash,
            'size' => $size,
            'original_filename' => $rawFile instanceof UploadedFile
                ? $rawFile->getClientOriginalName()
                : $rawFile->getFilename(),
        ];
    }
}
