<?php

namespace Algolia\AlgoliaSearchBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see
 * {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('algolia');
        $rootNode->children()
            ->scalarNode('application_id')->isRequired()->end()
            ->scalarNode('api_key')->isRequired()->end()
            ->integerNode('connection_timeout')->defaultNull()->end()
            ->scalarNode('index_name_prefix')->defaultNull()->end()
            ->booleanNode('catch_and_log_exceptions')->defaultFalse()->end();

        return $treeBuilder;
    }
}
