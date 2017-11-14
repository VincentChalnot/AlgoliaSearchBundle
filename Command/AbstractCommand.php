<?php

namespace Algolia\AlgoliaSearchBundle\Command;

use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;

/**
 * Common methods used by all commands
 */
class AbstractCommand extends ContainerAwareCommand
{
    /**
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \LogicException
     *
     * @return EntityManager
     */
    protected function getEntityManager(): EntityManager
    {
        return $this
            ->getContainer()
            ->get('doctrine.orm.entity_manager');
    }

    /**
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \LogicException
     *
     * @return array
     */
    protected function getEntityClasses(): array
    {
        $metaData = $this->getEntityManager()
            ->getMetadataFactory()
            ->getAllMetadata();

        return array_map(
            function (ClassMetadata $data) {
                return $data->getName();
            },
            $metaData
        );
    }
}
