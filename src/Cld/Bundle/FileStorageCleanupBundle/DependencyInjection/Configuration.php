<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cld_file_storage_cleanup');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('catalog_storage_root')
                    ->info('Local directory backing the catalogStorage Flysystem mount.')
                    ->defaultValue('%kernel.project_dir%/var/file_storage/catalog')
                ->end()
                ->booleanNode('enable_content_addressed_storer')
                    ->info('Decorate the FileStorer so re-uploads of byte-identical content reuse the existing file_key instead of creating a new copy.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('enable_api_media_dedup')
                    ->info('Make POST /api/rest/v1/media-files idempotent: byte-identical re-uploads reuse the existing key, and skip the product update entirely when the value already points at it.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('content_addressed_storages')
                    ->info('Storage aliases the content-addressed storer applies to.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['catalogStorage'])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
