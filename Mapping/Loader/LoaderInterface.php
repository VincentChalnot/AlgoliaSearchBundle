<?php

namespace Algolia\AlgoliaSearchBundle\Mapping\Loader;

use Algolia\AlgoliaSearchBundle\Mapping\IndexMetadata;

interface LoaderInterface
{
    /**
     * Extracts the Algolia metaData from an entity.
     *
     * @param string $class
     *
     * @return IndexMetadata
     */
    public function getMetaData($class);
}
