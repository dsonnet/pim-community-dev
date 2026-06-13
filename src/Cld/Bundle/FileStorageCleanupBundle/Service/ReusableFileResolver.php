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
        $path = $rawFile->getPathname();
        if (!is_file($path)) {
            return null;
        }

        $hash = @sha1_file($path);
        $size = @filesize($path);
        if (false === $hash || false === $size) {
            return null;
        }

        $originalFilename = $rawFile instanceof UploadedFile
            ? $rawFile->getClientOriginalName()
            : $rawFile->getFilename();

        $candidateKeys = $this->connection->fetchFirstColumn(
            'SELECT file_key FROM akeneo_file_storage_file_info
             WHERE storage = ? AND hash = ? AND size = ? AND original_filename = ?
             ORDER BY id ASC LIMIT 10',
            [$storageAlias, $hash, $size, $originalFilename]
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
}
