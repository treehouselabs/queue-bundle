<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\QueueBundle\CacheWarmer\AmqpCacheWarmer;

class QueueDeclareCommand extends Command
{
    /**
     * @var AmqpCacheWarmer
     */
    private $cacheWarmer;

    /**
     * @param AmqpCacheWarmer $cacheWarmer
     */
    public function __construct(AmqpCacheWarmer $cacheWarmer)
    {
        parent::__construct();

        $this->cacheWarmer = $cacheWarmer;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('queue:declare');
        $this->setDescription('Tries to declare all known exchanges and queues');
        $this->setHelp(<<<HELP
This utility command is useful for deployments to ensure all the configured
exchanges and queues are created on the message broker.
HELP
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cacheWarmer->warmUp('');

        $output->writeln('<info>All exchanges and queues are declared</info>');
    }
}
