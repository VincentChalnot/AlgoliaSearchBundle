<?php

namespace Algolia\AlgoliaSearchBundle\Mapping;

class Property
{
    protected $name;
    protected $algoliaName;

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

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
