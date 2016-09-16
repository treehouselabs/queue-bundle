<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\Queue\Consumer\ConsumerInterface;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\Consumer\Limiter\MemoryLimiter;
use TreeHouse\QueueBundle\Consumer\Limiter\MessagesLimiter;

class QueueConsumeCommand extends ContainerAwareCommand
{
    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('queue:consume');
        $this->addArgument('queue', InputArgument::REQUIRED, 'The name of the queue to consume from');
        $this->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', 50);
        $this->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of messages to consume. Set to 0 for indefinite consuming.', 0);
        $this->addOption('max-memory', 'm', InputOption::VALUE_OPTIONAL, 'Maximum amount of memory to use (in MB). The consumer will try to stop before this limit is reached. Set to 0 for indefinite consuming.', 0);
        $this->addOption('max-time', 't', InputOption::VALUE_OPTIONAL, 'Maximum execution time in seconds. Set to 0 for indefinite consuming', 0);
        $this->addOption('wait', 'w', InputOption::VALUE_OPTIONAL, 'Time in microseconds to wait before consuming the next message', 0);
        $this->addOption('min-duration', 'd', InputOption::VALUE_OPTIONAL, 'Duration that this command must run for', 15);
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('queue');

        /** @var ConsumerInterface $delegate */
        $delegate = $this->getContainer()->get(sprintf('tree_house.queue.consumer.%s', $name));
        $consumer = (new Consumer($delegate, $output, $this->getConsumerTag()))
            ->waitBetweenMessages((int) $input->getOption('wait'))
            ->flushAfter((int) $input->getOption('batch-size'))
            ->mustRunFor((int) $input->getOption('min-duration'))
        ;

        if ($limit = (int) $input->getOption('limit')) {
            $consumer->addLimiter(new MessagesLimiter($limit));
        }

        if ($maxMemory = (int) $input->getOption('max-memory')) {
            $consumer->addLimiter(new MemoryLimiter($maxMemory * 1024 * 1024));
        }

        $output->writeln(sprintf('Consuming from <info>%s</info> queue', $name));

        $consumer->consume();

        $output->writeln(
            sprintf(
                'Consumed <info>%d</info> messages in <info>%s seconds</info>',
                $consumer->getProcessed(),
                $consumer->getDuration()
            )
        );
    }

    /**
     * @return string
     */
    private function getConsumerTag()
    {
        return sprintf('%s-%s', $this->getName(), uniqid());
    }
}
