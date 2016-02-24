<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\Queue\Consumer;
use TreeHouse\Queue\Message\Publisher\MessagePublisherInterface;

class QueueDeclareCommand extends ContainerAwareCommand
{
    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('queue:declare');
        $this->setDescription('Tries to declare all known exchanges and queues');
        $this->addOption('exchange', 'x', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The name of the exchanges(s) to declare, defaults to all', []);
        $this->addOption('queue', 'u', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The name of the queue(s) to declare, defaults to all', []);
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
        $publishers = $this->loadPublishers($input->getOption('exchange'));
        foreach ($publishers as $name => $publisher) {
            // At the moment this results in the publishers exchange being declared automatically.
            // TODO The publisher should be able to init/declare its exchange explicitly.

            $output->writeln(sprintf('=> declared exchange <comment>%s</comment>', $name));
        }

        $consumers = $this->loadConsumers($input->getOption('queue'));
        foreach ($consumers as $name => $consumer) {
            // At the moment this results in the consumers queue being declared automatically.
            // TODO The consumer should be able to init/declare its queue explicitly.

            $output->writeln(sprintf('=> declared queue <comment>%s</comment>', $name));
        }

        $output->writeln('<info>All exchanges and queues are declared</info>');
    }

    /**
     * Loads the publisher services from the DIC.
     *
     * @param array $names
     *
     * @return MessagePublisherInterface[]
     */
    protected function loadPublishers(array $names)
    {
        $services = $this->getContainer()->getParameter('tree_house.queue.publishers');

        if (empty($names)) {
            $names = array_keys($services);
        }

        $publishers = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $services)) {
                throw new \RuntimeException(sprintf('Publisher "%s" is not configured', $name));
            }

            $publishers[$name] = $this->getContainer()->get($services[$name]);
        }

        return $publishers;
    }

    /**
     * Loads the consumer services from the DIC.
     *
     * @param array $names
     *
     * @return Consumer[]
     */
    protected function loadConsumers(array $names)
    {
        $services = $this->getContainer()->getParameter('tree_house.queue.consumers');

        if (empty($names)) {
            $names = array_keys($services);
        }

        $consumers = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $services)) {
                throw new \RuntimeException(sprintf('Consumer "%s" is not configured', $name));
            }

            $consumers[$name] = $this->getContainer()->get($services[$name]);
        }

        return $consumers;
    }
}
