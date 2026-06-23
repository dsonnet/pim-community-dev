<?php

declare(strict_types=1);

namespace Cld\Bundle\TextCollectionBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class CldTextCollectionExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $loader->load('attribute_types.yml');
        $loader->load('factories.yml');
        $loader->load('normalizers.yml');
        $loader->load('updaters.yml');
        $loader->load('array_converters.yml');
        $loader->load('comparators.yml');
        $loader->load('completeness.yml');
        $loader->load('query_builders.yml');
        $loader->load('providers.yml');
        $loader->load('validators.yml');
        $loader->load('form_types.yml');
        $loader->load('datagrid/attribute_types.yml');
        $loader->load('datagrid/filters.yml');
    }
}
