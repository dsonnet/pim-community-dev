<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\DependencyInjection\Compiler;

use Cld\Bundle\FileStorageCleanupBundle\Controller\ExternalApi\DedupMediaFileController;
use Cld\Bundle\FileStorageCleanupBundle\Service\ReusableFileResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * When cld_file_storage_cleanup.enable_api_media_dedup is true, swaps the external API
 * media-files controller for the dedup-aware subclass. Done in a compiler pass (not by
 * overriding the %pim_api.controller.media_file.class% parameter only) so the resolver
 * can be injected by setter without re-declaring the parent's constructor arguments.
 */
class ApiMediaDedupPass implements CompilerPassInterface
{
    private const CONTROLLER_SERVICE_ID = 'pim_api.controller.media_file';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('cld_file_storage_cleanup.enable_api_media_dedup')
            || true !== $container->getParameter('cld_file_storage_cleanup.enable_api_media_dedup')) {
            return;
        }

        if (!$container->hasDefinition(self::CONTROLLER_SERVICE_ID)) {
            throw new \LogicException(sprintf(
                'cld_file_storage_cleanup.enable_api_media_dedup is on but the "%s" service does not exist on this Akeneo version.',
                self::CONTROLLER_SERVICE_ID
            ));
        }

        $definition = $container->getDefinition(self::CONTROLLER_SERVICE_ID);
        $definition->setClass(DedupMediaFileController::class);
        $definition->addMethodCall('setReusableFileResolver', [new Reference(ReusableFileResolver::class)]);
    }
}
