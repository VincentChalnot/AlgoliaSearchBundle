<?php

namespace Algolia\AlgoliaSearchBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reindex all entities or just those of a specified type.
 */
class ReindexCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        $this
            ->setName('algolia:reindex')
            ->setDescription('Reindex all entities or just those of a specified type.')
            ->addArgument(
                'entityName',
                InputArgument::OPTIONAL,
                'Which type of entity do you want to reindex? If not set, all is assumed.'
            )
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Index inplace, without deleting out-dated records.')
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify a batch size for the reindexing operation.',
                1000
            )
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Wait for operations to complete before returning.');
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
        if ($input->getArgument('entityName')) {
            $filter = $this->getEntityManager()->getRepository($input->getArgument('entityName'))->getClassName();
        }

        foreach ($this->getEntityClasses() as $class) {
            if (!$filter || $class === $filter) {
                $toReindex[] = $class;
            }
        }

        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize === 0) {
            $output->writeln('<comment>Invalid batch size specified, assuming 1000.</comment>');
            $batchSize = 1000;
        }

        $safe = !$input->getOption('unsafe');

        $nIndexed = 0;
        foreach ($toReindex as $className) {
            $nIndexed += $this->reIndex($className, $batchSize, $safe);
        }

        if ($input->getOption('sync')) {
            $this->getContainer()->get('algolia.indexer')->waitForAlgoliaTasks();
        }

        switch ($nIndexed) {
            case 0:
                $output->writeln('No entity indexed');
                break;
            case 1:
                $output->writeln('<info>1</info> entity indexed');
                break;
            default:
                $output->writeln(sprintf('<info>%s</info> entities indexed', $nIndexed));
                break;
        }
    }

    /**
     * @param      $className
     * @param int  $batchSize
     * @param bool $safe
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \LogicException
     *
     * @return int
     */
    protected function reIndex($className, $batchSize = 1000, $safe = true)
    {
        $reIndexer = $this->getContainer()->get('algolia.indexer')->getManualIndexer($this->getEntityManager());

        return $reIndexer->reIndex(
            $className,
            [
                'batchSize' => $batchSize,
                'safe' => $safe,
                'clearEntityManager' => true,
            ]
        );
    }
}
