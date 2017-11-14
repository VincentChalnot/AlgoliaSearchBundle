<?php

namespace Algolia\AlgoliaSearchBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clear the index related to an entity or the entire index
 */
class ClearCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        $this
            ->setName('algolia:clean')
            ->setDescription('Clear the index related to an entity')
            ->addArgument(
                'entityName',
                InputArgument::OPTIONAL,
                'Which entity index do you want to clear? If not set, all is assumed.'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $toReindex = [];

        $filter = null;
        if ($input->hasArgument('entityName')) {
            $filter = $this->getEntityManager()->getRepository($input->getArgument('entityName'))->getClassName();
        }

        foreach ($this->getEntityClasses() as $class) {
            if (!$filter || $class === $filter) {
                $toReindex[] = $class;
            }
        }

        $nIndexed = 0;
        foreach ($toReindex as $className) {
            $nIndexed += $this->clear($className);
        }

        switch ($nIndexed) {
            case 0:
                $output->writeln('No entity cleared');
                break;
            case 1:
                $output->writeln('<info>1</info> entity cleared');
                break;
            default:
                $output->writeln(sprintf('<info>%s</info> entities cleared', $nIndexed));
                break;
        }
    }

    /**
     * @param string $className
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \LogicException
     */
    protected function clear($className)
    {
        $reIndexer = $this->getContainer()->get('algolia.indexer')->getManualIndexer($this->getEntityManager());

        $reIndexer->clear($className);
    }
}
