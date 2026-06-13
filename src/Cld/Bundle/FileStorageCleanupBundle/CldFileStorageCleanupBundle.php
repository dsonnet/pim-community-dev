<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle;

use Cld\Bundle\FileStorageCleanupBundle\DependencyInjection\Compiler\ApiMediaDedupPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class CldFileStorageCleanupBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ApiMediaDedupPass());
    }
}
