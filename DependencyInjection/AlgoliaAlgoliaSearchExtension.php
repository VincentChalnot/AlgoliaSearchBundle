<?php

namespace Algolia\AlgoliaSearchBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class AlgoliaAlgoliaSearchExtension extends Extension
{
    /**
     * @param array            $configs
     * @param ContainerBuilder $container
     *
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $clientDefinition = $container->getDefinition('algolia.client');
        $clientDefinition->replaceArgument(0, $config['application_id']);
        $clientDefinition->replaceArgument(1, $config['api_key']);
        if (null !== $config['connection_timeout']) {
            $clientDefinition->addMethodCall('setConnectTimeout', ['connection_timeout']);
        }

        if (null !== $config['index_name_prefix']) {
            $container
                ->getDefinition('algolia.indexer')
                ->addMethodCall('setIndexNamePrefix', ['index_name_prefix']);
        }

        if (null !== $config['catch_and_log_exceptions']) {
            $container
                ->getDefinition('algolia.indexer_subscriber')
                ->addMethodCall('setCatchAndLogExceptions', ['catch_and_log_exceptions']);
        }
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'algolia';
    }
}
