<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueDeclareCommand extends ContainerAwareCommand
{
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
        $warmer = $this->getContainer()->get('tree_house.queue.cache.warmer.amqp');
        $warmer->warmUp('');

        $output->writeln('<info>All exchanges and queues are declared</info>');
    }
}
