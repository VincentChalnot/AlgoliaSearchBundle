<?php

namespace Algolia\AlgoliaSearchBundle\Mapping;

class Index
{
    protected $algoliaName;
    protected $perEnvironment = true;
    protected $autoIndex = true;

    // The names of the settings only we care about (client side)
    protected static $internalSettingsProps = [
        'algoliaName',
        'perEnvironment',
        'autoIndex'
    ];

    // The names of the settings that Algolia servers care about
    public static $algoliaSettingsProps = [
        'minWordSizefor1Typo',
        'minWordSizefor2Typos',
        'hitsPerPage',
        'attributesToIndex',
        'searchableAttributes',
        'attributesToRetrieve',
        'unretrievableAttributes',
        'numericAttributesForFiltering',
        'optionalWords',
        'attributesForFaceting',
        'attributesToSnippet',
        'attributesToHighlight',
        'attributeForDistinct',
        'ranking',
        'customRanking',
        'separatorsToIndex',
        'removeWordsIfNoResults',
        'queryType',
        'highlightPreTag',
        'highlightPostTag',
        'slaves',
        'replicas',
        'synonyms',
    ];

    public function getAlgoliaName()
    {
        return $this->algoliaName;
    }

    public function setAlgoliaNameFromClass($class)
    {
        $this->algoliaName = substr($class, strrpos($class, '\\') + 1);

        return $this;
    }

    public function updateSettingsFromArray(array $settings)
    {
        foreach (self::$internalSettingsProps as $field) {
            if (array_key_exists($field, $settings)) {
                $this->$field = $settings[$field];
            }
        }

        foreach (self::$algoliaSettingsProps as $field) {
            if (array_key_exists($field, $settings)) {
                $this->$field = $settings[$field];
            }
        }

        return $this;
    }

    public function getAutoIndex()
    {
        return $this->autoIndex;
    }

    public function getPerEnvironment()
    {
        return $this->perEnvironment;
    }

    /**
     * Returns the index settings in a format
     * compatible with that expected by https://github.com/algolia/algoliasearch-client-php
     */
    public function getAlgoliaSettings()
    {
        $settings = [];

        foreach (self::$algoliaSettingsProps as $field) {
            if (isset($this->$field)) {
                $settings[$field] = $this->$field;
            }
        }

        return $settings;
    }
}
