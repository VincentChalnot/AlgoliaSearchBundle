<?php

namespace Algolia\AlgoliaSearchBundle\Indexer;

use Algolia\AlgoliaSearchBundle\Mapping\IndexMetadata;
use Algolia\AlgoliaSearchBundle\Mapping\Loader\LoaderInterface;
use AlgoliaSearch\Client;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Symfony\Component\PropertyAccess\PropertyAccess;

use Algolia\AlgoliaSearchBundle\Exception\UnknownEntity;
use Algolia\AlgoliaSearchBundle\Exception\NoPrimaryKey;
use Algolia\AlgoliaSearchBundle\Exception\NotAnAlgoliaEntity;
use Algolia\AlgoliaSearchBundle\SearchResult\SearchResult;

/**
 * Takes care of indexing all the entities
 */
class Indexer
{
    /** @var Client */
    protected $client;

    /** @var EntityManager */
    protected $em;

    /** @var LoaderInterface */
    protected $metadataLoader;

    /** @var string */
    protected $env;

    /** @var string */
    protected $indexNamePrefix;

    /**
     * Holds index settings for entities we're interested in.
     *
     * Keys are fully qualified class names (i.e. with namespace),
     * values are as returned by the MetaDataLoader.
     *
     * Please see the documentation for MetaDataLoaderInterface::getMetaData
     * for more details.
     *
     * @var IndexMetadata[]
     */
    protected $indexMetadatas = [];

    /** @var array */
    protected $ignoredClasses = [];

    // Used to wait for sync, keys are index names
    protected $latestAlgoliaTaskID = [];

    // Cache index objects from the php client lib
    protected $indices = [];

    /**
     * @param Client          $client
     * @param EntityManager   $em
     * @param LoaderInterface $metadataLoader
     * @param string          $env
     */
    public function __construct(
        Client $client,
        EntityManager $em,
        LoaderInterface $metadataLoader,
        string $env
    ) {
        \AlgoliaSearch\Version::$custom_value = ' Symfony';
        $this->em = $em;
        $this->metadataLoader = $metadataLoader;
        $this->env = $env;
    }

    /**
     * @param string $indexNamePrefix
     *
     * @internal
     */
    public function setIndexNamePrefix(string $indexNamePrefix)
    {
        $this->indexNamePrefix = $indexNamePrefix;
    }

    /**
     * @return IndexMetadata[]
     */
    public function getIndexMetadatas(): array
    {
        return $this->indexMetadatas;
    }

    /**
     * @param string $class
     *
     * @throws UnknownEntity
     *
     * @return IndexMetadata
     */
    public function getIndexMetadata(string $class)
    {
        if (!$this->hasIndexMetadata($class)) {
            throw new UnknownEntity("No entity class `{$class}` in metadata index");
        }

        return $this->indexMetadatas[$class];
    }

    /**
     * This function does 2 things at once for efficiency:
     * - return a simple boolean telling us whether or not there might be
     *   indexing work to do with this entity class
     * - extract, and store for later, the index settings for the entity class
     *   if we're interested in indexing it
     *
     * @param string $class
     *
     * @return bool
     */
    public function hasIndexMetadata(string $class)
    {
        if ($this->isIgnoredClass($class)) {
            return false;
        }

        if ($this->hasComputedMetadata($class)) {
            return true;
        }

        $metadata = $this->metadataLoader->getMetaData($class);
        if (false === $metadata) {
            $this->addIgnoredClass($class);

            return false;
        }

        $this->indexMetadatas[$class] = $metadata;

        return true;
    }

    /**
     * Only test already built index metadata
     *
     * @param string $class
     *
     * @return bool
     */
    protected function hasComputedMetadata(string $class)
    {
        return array_key_exists($class, $this->indexMetadatas);
    }

    /**
     * @param string $class
     *
     * @return bool
     */
    protected function isIgnoredClass(string $class)
    {
        return \in_array($class, $this->ignoredClasses, true);
    }

    /**
     * @param string $class
     */
    protected function addIgnoredClass(string $class)
    {
        $this->ignoredClasses[] = $class;
    }

    /**
     * Determines whether the IndexIf conditions allow indexing this entity.
     * If a changeSet is specified, returns array($shouldBeIndexedNow, $wasIndexedBefore),
     * Otherwise just returns whether it should be indexed now.
     *
     * @param object     $entity
     * @param array|null $changeSet
     *
     * @throws \Algolia\AlgoliaSearchBundle\Exception\UnknownEntity
     *
     * @return array|bool
     */
    protected function shouldIndex(object $entity, array $changeSet = null)
    {
        $needsIndexing = true;
        $wasIndexed = true;
        $indexMetadata = $this->getIndexMetadata(ClassUtils::getClass($entity));

        foreach ($indexMetadata->getIndexIfs() as $if) {
            if (null === $changeSet) {
                if (!$if->evaluate($entity)) {
                    return false;
                }
            } else {
                list ($newValue, $oldValue) = $if->diff($entity, $changeSet);
                $needsIndexing = $needsIndexing && $newValue;
                $wasIndexed = $wasIndexed && $oldValue;
            }
        }

        return null === $changeSet ? true : [$needsIndexing, $wasIndexed];
    }

    /**
     * Determines whether the IndexIf conditions allowed the entity
     * to be indexed when the entity had the internal values provided
     * in the $originalData array.
     *
     * @param object $entity
     * @param array  $originalData
     *
     * @throws \Algolia\AlgoliaSearchBundle\Exception\UnknownEntity
     *
     * @return bool
     */
    protected function shouldHaveBeenIndexed(object $entity, array $originalData)
    {
        $indexMetadata = $this->getIndexMetadata(ClassUtils::getClass($entity));

        foreach ($indexMetadata->getIndexIfs() as $if) {
            if (!$if->evaluateWith($entity, $originalData)) {
                return false;
            }
        }

        return true;
    }

    protected function extractPropertyValue(object $entity, $field, $depth)
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $value = $accessor->getValue($entity, $field);

        if ($value instanceof \Doctrine\Common\Collections\Collection) {
            if ($depth >= 2) {
                return null;
            }

            $value = $value->toArray();

            if (\count($value) > 0) {
                if (!$this->discoverEntity(reset($value), $this->em)) {
                    throw new NotAnAlgoliaEntity(
                        'Tried to index `'.$field.'` relation which is a `'.\get_class(
                            reset($value)
                        ).'` instance, which is not recognized as an entity to index.'
                    );
                }
            }

            $value = array_map(
                function ($val) use ($depth) {
                    return $this->getFieldsForAlgolia($val, null, $depth + 1);
                },
                $value
            );
        }

        if (\is_object($value) && $this->isEntity($this->em, $value)) {
            if ($depth >= 2) {
                return null;
            }

            if (!$this->discoverEntity($value, $this->em)) {
                throw new NotAnAlgoliaEntity(
                    'Tried to index `'.$field.'` relation which is a `'.\get_class(
                        $value
                    ).'` instance, which is not recognized as an entity to index.'
                );
            }

            $value = $this->getFieldsForAlgolia($value, null, $depth + 1);
        }


        return $value;
    }

    /**
     * @internal
     * Returns a pair of json encoded arrays [newPrimaryKey, oldPrimaryKey]
     * Where oldPrimaryKey is null if the primary key did not change,
     * which is most of the times!
     */
    public function getPrimaryKeyForAlgolia($entity, array $changeSet = null, $depth = 0)
    {
        $class = $this->getClass($entity);
        if (!isset(self::$indexSettings[$class])) {
            throw new UnknownEntity("Entity `$class` is not known to Algolia. This is likely an implementation bug.");
        }

        $changed = false;

        $oldPrimaryKeyValues = [];
        $newPrimaryKeyValues = [];

        foreach (self::$indexSettings[$class]->getIdentifierFieldNames() as $fieldName) {
            $old = null;
            $new = null;

            if (\is_array($changeSet) && array_key_exists($fieldName, $changeSet)) {
                $old = $changeSet[$fieldName][0];
                $new = $changeSet[$fieldName][1];
                $changed = true;
            } else {
                $old = $new = $this->extractPropertyValue($entity, $fieldName, $depth);
            }

            if (!$new) {
                throw new NoPrimaryKey(
                    "An entity without a valid primary key was found during synchronization with Algolia."
                );
            }

            $oldPrimaryKeyValues[$fieldName] = $old;
            $newPrimaryKeyValues[$fieldName] = $new;
        }

        $primaryKey = $this->serializePrimaryKey($newPrimaryKeyValues);
        $oldPrimaryKey = $changed ? $this->serializePrimaryKey($oldPrimaryKeyValues) : null;

        return [$primaryKey, $oldPrimaryKey];
    }

    /**
     * @todo: This function should be made simpler,
     * but it seems currently the PHP client library fails
     * to decode responses from Algolia when we put JSON or
     * serialized objects in the objectIDs.
     *
     * Tests have been adapted to use this function too,
     * so changing it to something else should not break any test.
     * @internal
     *
     */
    public function serializePrimaryKey(array $values)
    {
        return base64_encode(json_encode($values));
    }

    /**
     * @internal
     */
    public function unserializePrimaryKey($pkey)
    {
        return json_decode(base64_decode($pkey), true);
    }

    /**
     * @internal
     */
    public function getFieldsForAlgolia($entity, array $changeSet = null, $depth = 0)
    {
        $class = $this->getClass($entity);

        if (!isset(self::$indexSettings[$class])) {
            throw new UnknownEntity(
                "Entity of class `$class` is not known to Algolia. This is likely an implementation bug."
            );
        }

        $fields = [];

        // Get fields coming from properties
        foreach (self::$indexSettings[$class]->getProperties() as $prop) {
            $fields[$prop->getAlgoliaName()] = $this->extractPropertyValue($entity, $prop->getName(), $depth);
        }

        // Get fields coming from methods
        foreach (self::$indexSettings[$class]->getMethods() as $meth) {
            $fields[$meth->getAlgoliaName()] = $meth->evaluate($entity);
        }

        return $fields;
    }

    /**
     * @internal
     */
    public function getAlgoliaIndexName($entity_or_class)
    {
        $class = \is_object($entity_or_class) ? $this->getClass($entity_or_class) : $entity_or_class;

        if (!isset(self::$indexSettings[$class])) {
            throw new UnknownEntity("Entity $class is not known to Algolia. This is likely an implementation bug.");
        }

        $index = self::$indexSettings[$class]->getIndex();
        $indexName = $index->getAlgoliaName();

        if (!empty($this->indexNamePrefix)) {
            $indexName = $this->indexNamePrefix.'_'.$indexName;
        }

        if ($index->getPerEnvironment() && $this->env) {
            $indexName .= '_'.$this->env;
        }

        return $indexName;
    }


    /**
     * Keep track of a remote task to be able to wait for it later.
     * Since it is enough to check that the task with the higher taskID is complete to
     * conclude that tasks with lower taskID's are done, we only store the latest one.
     *
     * We also store the index object itself, that way, when we call waitForAlgoliaTasks,
     * we don't have to call getIndex, which would otherwise create the index in some cases.
     * This makes sure we don't accidentally create an index when just waiting for its deletion.
     *
     * @internal
     */
    public function algoliaTask($indexName, $res)
    {
        if (!empty($res['taskID'])) {
            if (!isset($this->latestAlgoliaTaskID[$indexName]) || $res['taskID'] > $this->latestAlgoliaTaskID[$indexName]['taskID']) {
                $this->latestAlgoliaTaskID[$indexName] = [
                    'index' => $this->getIndex($indexName),
                    'taskID' => $res['taskID'],
                ];
            }
        }

        return $res;
    }

    /**
     * This function does creations or updates - it sends full resources,
     * whether new or updated.
     *
     * @internal
     */
    protected function performBatchCreations(array $creations)
    {
        foreach ($creations as $indexName => $objects) {
            $this->algoliaTask(
                $indexName,
                $this->getIndex($indexName)->saveObjects($objects)
            );
        }
    }

    /**
     * This function does updates in the sense of PATCHes,
     * i.e. it handles deltas.
     *
     * @internal
     */
    protected function performBatchUpdates(array $updates)
    {
        foreach ($updates as $indexName => $objects) {
            $this->algoliaTask(
                $indexName,
                $this->getIndex($indexName)->saveObjects($objects)
            );
        }
    }

    /**
     * This performs deletions, no trick here.
     *
     * @internal
     */
    protected function performBatchDeletions(array $deletions)
    {
        foreach ($deletions as $indexName => $objectIDs) {
            $this->algoliaTask(
                $indexName,
                $this->getIndex($indexName)->deleteObjects($objectIDs)
            );
        }
    }

    /**
     * @internal
     */
    public function removeScheduledIndexChanges()
    {
        $this->entitiesScheduledForCreation = [];
        $this->entitiesScheduledForUpdate = [];
        $this->entitiesScheduledForDeletion = [];

        return $this;
    }

    public function getManualIndexer()
    {
        return new ManualIndexer($this, $this->em); // @todo refactor using dependency injection
    }

    /**
     * Return a properly configured instance of the Algolia PHP client library
     * and caches it.
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Returns an object used to communicate with the Algolia indexes
     * and caches it.
     *
     * @internal
     */
    public function getIndex($indexName)
    {
        if (!isset($this->indices[$indexName])) {
            $this->indices[$indexName] = $this->getClient()->initIndex($indexName);
        }

        return $this->indices[$indexName];
    }

    /**
     * Add the correct environment suffix to an index name,
     * this is primarily used by rawSearch as in rawSearch we don't want
     * the user to bother about knowing the environment he's on.
     *
     * @internal
     */
    public function makeEnvIndexName($indexName, $perEnvironment)
    {
        if ($perEnvironment) {
            return $indexName.'_'.$this->env;
        } else {
            return $indexName;
        }
    }

    /**
     * Performs a raw search in the Algolia indexes, i.e. will not involve
     * the local DB at all, and only return what's indexed on Algolia's servers.
     *
     * @param  string $indexName         The name of the index to search from.
     * @param  string $queryString       The query string.
     * @param  array  $options           Any search option understood by
     *                                   https://github.com/algolia/algoliasearch-client-php, plus:
     *                                   - perEnvironment: automatically suffix the index name with the environment,
     *                                   defaults to true
     *                                   - adaptIndexName: transform the index name as needed (e.g. add environment
     *                                   suffix), defaults to true. This option is here because sometimes we already
     *                                   have the suffixed index name, so calling rawSearch with adaptIndexName = false
     *                                   ensures we end up with the correct Algolia index name.
     *
     * @return SearchResult The results returned by Algolia. The `isHydrated` method of the result will return false.
     */
    public function rawSearch($indexName, $queryString, array $options = [])
    {
        $defaultOptions = [
            'perEnvironment' => true,
            'adaptIndexName' => true,
        ];

        $options = array_merge($defaultOptions, $options);

        $client = $this->getClient();

        if ($options['adaptIndexName']) {
            $indexName = $this->makeEnvIndexName($indexName, $options['perEnvironment']);
        }

        // these are not a real search option:
        unset($options['perEnvironment']);
        unset($options['adaptIndexName']);

        $index = $this->getIndex($indexName);

        return new SearchResult($index->search($queryString, $options));
    }

    /**
     * Perform a 'native' search on the Algolia servers.
     * 'Native' means that once the results are retrieved, they will be fetched from the local DB
     * and replaced with native ORM entities.
     *
     * @param  EntityManager $em          The Doctrine Entity Manager to use to fetch entities when hydrating the
     *                                    results.
     * @param  string        $indexName   The name of the index to search from.
     * @param  string        $queryString The query string.
     * @param  array         $options     Any search option understood by
     *                                    https://github.com/algolia/algoliasearch-client-php
     *
     * @return SearchResult  The results returned by Algolia. The `isHydrated` method of the result will return true.
     */
    public function search(EntityManager $em, $entityName, $queryString, array $options = [])
    {
        $entityClass = $em->getRepository($entityName)->getClassName();

        if (!$this->discoverEntity($entityClass, $em)) {
            throw new NotAnAlgoliaEntity(
                'Can\'t search, entity of class `'.$entityClass.'` is not recognized as an Algolia enriched entity.'
            );
        }

        // We're already finding the right index ourselves.
        $options['adaptIndexName'] = false;

        $indexName = $this->getAlgoliaIndexName($entityClass);

        // get results from Algolia
        $results = $this->rawSearch($indexName, $queryString, $options);

        $hydratedHits = [];

        // hydrate them as Doctrine entities
        foreach ($results->getHits() as $result) {
            $id = $this->unserializePrimaryKey($result['objectID']);
            $entity = $em->find($entityClass, $id);
            $hydratedHits[] = $entity;
        }

        return new SearchResult($results->getOriginalResult(), $hydratedHits);
    }

    /**
     * @internal
     */
    public function deleteIndex($indexName, array $options = [])
    {
        $defaultOptions = [
            'perEnvironment' => true,
            'adaptIndexName' => true,
        ];

        $options = array_merge($defaultOptions, $options);

        $client = $this->getClient();

        if ($options['adaptIndexName']) {
            $indexName = $this->makeEnvIndexName($indexName, $options['perEnvironment']);
        }

        $this->algoliaTask(
            $indexName,
            $this->getClient()->deleteIndex($indexName)
        );

        if (isset($this->indices[$indexName])) {
            unset($this->indices[$indexName]);
        }

        return $this;
    }

    /**
     * @internal
     */
    public function setIndexSettings($indexName, array $settings, array $options = [])
    {
        $defaultOptions = [
            'perEnvironment' => true,
            'adaptIndexName' => true,
        ];

        $options = array_merge($defaultOptions, $options);

        $client = $this->getClient();

        if ($options['adaptIndexName']) {
            $indexName = $this->makeEnvIndexName($indexName, $options['perEnvironment']);
        }

        $this->algoliaTask(
            $indexName,
            $this->getIndex($indexName)->setSettings($settings)
        );

        return $this;
    }

    /**
     * Wait for all Algolia tasks recorded by `algoliaTask` to complete.
     */
    public function waitForAlgoliaTasks()
    {
        foreach ($this->latestAlgoliaTaskID as $indexName => $data) {
            $data['index']->waitTask($data['taskID']);
            unset($this->latestAlgoliaTaskID[$indexName]);
        }

        return $this;
    }
}
