<?php

namespace Algolia\AlgoliaSearchBundle\Mapping;

class Method extends Helper\ChangeAwareMethod
{
    protected $algoliaName;

    public function setAlgoliaName($algoliaName)
    {
        $this->algoliaName = $algoliaName;

        return $this;
    }

    public function getAlgoliaName()
    {
        return $this->algoliaName;
    }
}
