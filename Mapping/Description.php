<?php

namespace Algolia\AlgoliaSearchBundle\Mapping;

use Algolia\AlgoliaSearchBundle\Mapping\Helper\ChangeAwareMethod;

class IndexMetadata
{
    /** @var string */
    protected $class;
    protected $index;
    protected $properties = [];

    /** @var Method[] */
    protected $methods = [];

    /** @var ChangeAwareMethod[] */
    protected $indexIfs = [];
    protected $identifierAttributeNames = [];

    /**
     * @param string $class
     */
    public function __construct(string $class)
    {
        $this->class = $class;
    }

    /**
     * @param Index $index
     */
    public function setIndex(Index $index)
    {
        $this->index = $index;
    }

    /**
     * @return Index
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * @param Method $m
     */
    public function addMethod(Method $m)
    {
        $this->methods[] = $m;
    }

    public function getMethods()
    {
        return $this->methods;
    }

    public function addProperty(Property $p)
    {
        $this->properties[] = $p;

        return $this;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function isEmpty()
    {
        return empty($this->properties) && empty($this->methods);
    }

    public function setIdentifierAttributeNames(array $fields)
    {
        $this->identifierAttributeNames = $fields;

        return $this;
    }

    public function addIdentifierAttributeName($field)
    {
        $this->identifierAttributeNames[] = $field;

        return $this;
    }

    public function getIdentifierFieldNames()
    {
        return $this->identifierAttributeNames;
    }

    public function hasIdentifierFieldNames()
    {
        return !empty($this->identifierAttributeNames);
    }

    public function addIndexIf(IndexIf $iif)
    {
        $this->indexIfs[] = $iif;

        return $this;
    }

    /**
     * @return ChangeAwareMethod[]
     */
    public function getIndexIfs()
    {
        return $this->indexIfs;
    }
}
