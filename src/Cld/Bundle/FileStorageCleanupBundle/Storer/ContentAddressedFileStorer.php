<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Storer;

use Akeneo\Tool\Component\FileStorage\File\FileStorerInterface;
use Akeneo\Tool\Component\FileStorage\Model\FileInfoInterface;
use Cld\Bundle\FileStorageCleanupBundle\Service\ReusableFileResolver;

/**
 * Root-cause guard against file_storage bloat.
 *
 * Akeneo's stock FileStorer generates a brand-new key on EVERY store() call —
 * PathGenerator derives it from sha1(filename . microtime()) — so re-importing an
 * unchanged image registers a new physical copy each time and orphans the previous
 * one. This decorator makes storing idempotent for identical content: when a file
 * with the same content hash, size and original filename is already registered on
 * the target storage and still present on disk, the existing FileInfo is reused and
 * no new copy is written.
 *
 * Safe because stored files are immutable in Akeneo (a value change always points to
 * a new key, nothing edits bytes in place) and CE deletes media neither on product
 * removal nor value change. The companion purge command also never deletes referenced
 * files, so sharing one key across many products cannot cause a dangling reference.
 */
class ContentAddressedFileStorer implements FileStorerInterface
{
    /** @param string[] $enabledStorages */
    public function __construct(
        private readonly FileStorerInterface $decorated,
        private readonly ReusableFileResolver $resolver,
        private readonly array $enabledStorages = ['catalogStorage'],
    ) {
    }

    public function store(\SplFileInfo $rawFile, string $destFsAlias, bool $deleteRawFile = false): FileInfoInterface
    {
        if (!in_array($destFsAlias, $this->enabledStorages, true)) {
            return $this->decorated->store($rawFile, $destFsAlias, $deleteRawFile);
        }

        $existing = $this->resolver->resolve($rawFile, $destFsAlias);
        if (null === $existing) {
            return $this->decorated->store($rawFile, $destFsAlias, $deleteRawFile);
        }

        if ($deleteRawFile && is_file($rawFile->getPathname())) {
            @unlink($rawFile->getPathname());
        }

        return $existing;
    }
}
