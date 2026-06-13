<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class CldFileStorageCleanupExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('cld_file_storage_cleanup.catalog_storage_root', $config['catalog_storage_root']);
        $container->setParameter('cld_file_storage_cleanup.content_addressed_storages', $config['content_addressed_storages']);
        $container->setParameter('cld_file_storage_cleanup.enable_api_media_dedup', $config['enable_api_media_dedup']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        if (true === $config['enable_content_addressed_storer']) {
            $loader->load('storer.yml');
        }
    }
}
