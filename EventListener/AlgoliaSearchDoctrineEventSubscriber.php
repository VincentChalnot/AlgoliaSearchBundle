<?php

namespace Algolia\AlgoliaSearchBundle\EventListener;

use Algolia\AlgoliaSearchBundle\Exception\UnknownEntity;
use Algolia\AlgoliaSearchBundle\Indexer\Indexer;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Listens to Doctrine events to reindex updated/removed entities
 */
class AlgoliaSearchDoctrineEventSubscriber implements EventSubscriber
{
    /** @var Indexer */
    protected $indexer;

    /** @var LoggerInterface */
    protected $logger;

    /** @var bool */
    protected $catchAndLogExceptions;

    /*
     * The arrays below hold the entities we will sync with
     * Algolia on postFlush.
     *
     * holds either naked entities or arrays of the form [
     *   'entity' => $someEntity,
     *   'indexName' => 'index name to override where the entity should normally go'
     * ]
     */
    /**
     * Holds arrays like ['entity' => $entity, 'changeSet' => $changeSet]
     *
     * @var array
     */
    protected $entitiesScheduledForCreation = [];

    /**
     * Holds arrays like ['entity' => $entity, 'changeSet' => $changeSet]
     *
     * @var array
     */
    protected $entitiesScheduledForUpdate = [];

    /**
     * Holds arrays like ['objectID' => 'aStringID', 'index' => 'anIndexName']
     *
     * @var array
     */
    protected $entitiesScheduledForDeletion = [];

    /**
     * Under normal circumstances, the service loader will set the indexer.
     *
     * @param Indexer              $indexer
     * @param LoggerInterface|null $logger
     */
    public function __construct(Indexer $indexer, LoggerInterface $logger = null)
    {
        $this->indexer = $indexer;
        $this->logger = $logger;
    }

    /**
     * @param bool $catchAndLogExceptions
     */
    public function setCatchAndLogExceptions(bool $catchAndLogExceptions)
    {
        $this->catchAndLogExceptions = $catchAndLogExceptions;
    }

    /**
     * The events we're interested in.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return [
            Events::onFlush,
            Events::postFlush,
        ];
    }

    /**
     * During onFlush, we tell the indexer what it should
     * index or unindex right after the data has been committed to the DB.
     *
     * By right after, I mean during the postFlush callback.
     * This is done to avoid sending wrong data to Algolia
     * if the local DB rejected our changes.
     *
     * @param OnFlushEventArgs $args
     *
     * @throws \Exception
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        try {
            /**
             * There might have been an exception thrown during the previous flush attempt,
             * because the DB rejected our changes for instance.
             * We clean our indexer cache to prevent double indexing stuff if this happened.
             */
            $this->indexer->removeScheduledIndexChanges();

            $em = $args->getEntityManager();
            $uow = $em->getUnitOfWork();

            foreach ($uow->getScheduledEntityInsertions() as $entity) {
                if ($this->isAutoIndex($entity)) {
                    $this->create($entity);
                }
            }

            foreach ($uow->getScheduledEntityUpdates() as $entity) {
                if ($this->isAutoIndex($entity)) {
                    $changeSet = $uow->getEntityChangeSet($entity);
                    $this->update($entity, $changeSet);
                }
            }

            foreach ($uow->getScheduledEntityDeletions() as $entity) {
                if ($this->isAutoIndex($entity)) {
                    $originalData = $uow->getOriginalEntityData($entity);
                    $this->delete($entity, $originalData);
                }
            }

            /**
             * There are also:
             *
             * $uow->getScheduledCollectionDeletions();
             * $uow->getScheduledCollectionUpdates();
             *
             * But they're not relevant here, I think.
             *
             * Apparently they're used for internal bookkeeping when
             * doing things with Many-To-Many relationships.
             *
             * Leaving the comment just in case I'm wrong.
             */
        } catch (\Exception $e) {
            if (!$this->catchAndLogExceptions) {
                throw $e;
            }
            if ($this->logger) {
                $this->logger->error('AlgoliaSearch: '.$e->getMessage());
            }
        }
    }

    /**
     * Real work happens here, but it is delegated to the indexer.
     *
     * @throws \Exception
     */
    public function postFlush()
    {
        try {
            $this->indexer->processScheduledIndexChanges();
        } catch (\Exception $e) {
            if (!$this->catchAndLogExceptions) {
                throw $e;
            }
            if ($this->logger) {
                $this->logger->error('AlgoliaSearch: '.$e->getMessage());
            }
        }
    }

    /**
     * Tells us whether we need to autoindex this entity.
     *
     * @param object $entity
     *
     * @return bool
     */
    protected function isAutoIndex(object $entity)
    {
        try {
            return $this->indexer->getClassMetadata(ClassUtils::getClass($entity))->getIndex()->getAutoIndex();
        } catch (UnknownEntity $e) {
            return false;
        }
    }
    /**
     * @internal
     */
    public function scheduleEntityCreation($entity, $checkShouldIndex = true)
    {
        if ($checkShouldIndex && !$this->shouldIndex(is_object($entity) ? $entity : $entity['entity'])) {
            return;
        }

        // We store the whole entity, because its ID will not be available until post-flush
        $this->entitiesScheduledForCreation[] = $entity;
    }

    /**
     * @internal
     */
    public function scheduleEntityUpdate($entity, array $changeSet)
    {
        list($shouldIndex, $wasIndexed) = $this->shouldIndex($entity, $changeSet);

        if ($shouldIndex) {
            if ($wasIndexed) {
                // We need to store the changeSet now, as it will not be available post-flush
                $this->entitiesScheduledForUpdate[] = ['entity' => $entity, 'changeSet' => $changeSet];
            } else {
                $this->scheduleEntityCreation($entity, ($checkShouldIndex = false));
            }
        } elseif ($wasIndexed) {
            // If the entity was indexed, and now should not be, then remove it.
            $this->scheduleEntityDeletion($entity, null);
        }
    }

    /**
     * @internal
     */
    public function scheduleEntityDeletion($entity, array $originalData = null)
    {
        // Don't unindex entities that were not already indexed!
        if (null !== $originalData && !$this->shouldHaveBeenIndexed($entity, $originalData)) {
            return;
        }

        // We need to get the primary key now, because post-flush it will be gone from the entity
        list($primaryKey, $unusedOldPrimaryKey) = $this->getPrimaryKeyForAlgolia($entity);
        $this->entitiesScheduledForDeletion[] = [
            'objectID' => $primaryKey,
            'index' => $this->getAlgoliaIndexName($entity),
        ];
    }

    /**
     * @param $entity
     */
    protected function create($entity)
    {
        $this->indexer->scheduleEntityCreation($entity);
    }

    /**
     * @param $entity
     * @param $changeSet
     */
    protected function update($entity, $changeSet)
    {
        $this->indexer->scheduleEntityUpdate($entity, $changeSet);
    }

    /**
     * @param $entity
     * @param $originalData
     */
    protected function delete($entity, $originalData)
    {
        $this->indexer->scheduleEntityDeletion($entity, $originalData);
    }


    /**
     * @internal
     */
    public function processScheduledIndexChanges()
    {
        $creations = [];
        $updates = [];
        $deletions = [];

        foreach ($this->entitiesScheduledForCreation as $entity) {
            if (is_object($entity)) {
                $index = $this->getAlgoliaIndexName($entity);
            } else {
                $index = $entity['indexName'];
                $entity = $entity['entity'];
            }

            list($primaryKey, $unusedOldPrimaryKey) = $this->getPrimaryKeyForAlgolia($entity);
            $fields = $this->getFieldsForAlgolia($entity);

            if (!empty($fields)) {
                if (!isset($creations[$index])) {
                    $creations[$index] = [];
                }
                $fields['objectID'] = $primaryKey;
                $creations[$index][] = $fields;
            }
        }

        foreach ($this->entitiesScheduledForUpdate as $data) {
            $index = $this->getAlgoliaIndexName($data['entity']);

            list($primaryKey, $oldPrimaryKey) = $this->getPrimaryKeyForAlgolia($data['entity'], $data['changeSet']);

            // The very unlikely case where a primary key changed
            if (null !== $oldPrimaryKey) {
                if (!isset($deletions[$index])) {
                    $deletions[$index] = [];
                }
                $deletions[$index][] = $oldPrimaryKey;

                $fields = $this->getFieldsForAlgolia($data['entity'], null);
                $fields['objectID'] = $primaryKey;

                if (!isset($creations[$index])) {
                    $creations[$index] = [];
                }
                $creations[$index][] = $fields;
            } else {
                $fields = $this->getFieldsForAlgolia($data['entity'], $data['changeSet']);

                if (!empty($fields)) {
                    if (!isset($updates[$index])) {
                        $updates[$index] = [];
                    }
                    $fields['objectID'] = $primaryKey;
                    $updates[$index][] = $fields;
                }
            }
        }

        foreach ($this->entitiesScheduledForDeletion as $data) {
            $index = $data['index'];

            if (!isset($deletions[$index])) {
                $deletions[$index] = [];
            }
            $deletions[$index][] = $data['objectID'];
        }

        $this->performBatchCreations($creations);
        $this->performBatchUpdates($updates);
        $this->performBatchDeletions($deletions);

        $this->removeScheduledIndexChanges();
    }
}
