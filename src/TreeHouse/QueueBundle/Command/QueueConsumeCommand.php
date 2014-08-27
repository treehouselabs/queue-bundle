<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\Queue\Message\Message;
use TreeHouse\Queue\Message\Provider\MessageProviderInterface;
use TreeHouse\Queue\Processor\ProcessorInterface;
use TreeHouse\QueueBundle\QueueEvents;

class QueueConsumeCommand extends ContainerAwareCommand
{
    /**
     * @var OutputInterface
     */
    protected $output;

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
        $this->addOption('wait', 'w', InputOption::VALUE_OPTIONAL, 'Time in microseconds to wait before polling the next message', 10000);
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name      = $input->getArgument('queue');
        $batchSize = intval($input->getOption('batch-size'));
        $wait      = intval($input->getOption('wait'));
        $limit     = intval($input->getOption('limit'));
        $maxMemory = intval($input->getOption('max-memory')) * 1024 * 1024;
        $maxTime   = intval($input->getOption('max-time'));

        $this->output = $output;

        $provider   = $this->getMessageProvider($name);
        $processor  = $this->getProcessor($name);

        $this->output(sprintf('Consuming from <info>%s</info> queue', $name));

        $start       = time();
        $minDuration = 15;

        $processed = 0;
        while (true) {
            if (null !== $message = $provider->get()) {
                $res = $processor->process($message);
                if ($res === true) {
                    $provider->ack($message);

                    $output->writeln(
                        sprintf('Processed payload <info>%s</info>', $this->getPayloadOutput($message, 20)),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                } else {
                    $output->writeln('<error>Something went wrong</error>');

                    if (!is_bool($res)) {
                        $output->writeln(
                            sprintf(
                                '<error>Did you forget to return a boolean value in the %s processor?</error>',
                                get_class($processor)
                            )
                        );
                    }
                }

                // see if batch is completed
                if (++$processed % $batchSize === 0) {
                    $this->flush();
                }

                if (($limit > 0) && ($processed >= $limit)) {
                    $this->output(
                        sprintf('Maximum number of messages consumed (%d)', $limit),
                        OutputInterface::VERBOSITY_VERBOSE
                    );

                    break;
                }

                if (($maxMemory > 0) && memory_get_usage(true) > $maxMemory) {
                    $this->output(
                        sprintf('Memory peak of %dMB reached', $maxMemory / 1024 / 1024),
                        OutputInterface::VERBOSITY_VERBOSE
                    );

                    break;
                }

                if (($maxTime > 0) && ((time() - $start) > $maxTime)) {
                    $this->output(
                        sprintf('Maximum execution time of %ds reached', $maxTime),
                        OutputInterface::VERBOSITY_VERBOSE
                    );

                    break;
                }
            }

            $this->output(sprintf('Sleeping for %dms', $wait / 1000), OutputInterface::VERBOSITY_DEBUG);
            usleep($wait);
        }

        // flush remaining changes
        $this->flush();

        // make sure consumer doesn't quit to quickly, or supervisor will mark it as a failed restart,
        // and putting the process in FATAL state.
        $duration = time() - $start;
        if ($duration < $minDuration) {
            $time = $minDuration - $duration;
            $this->output(
                sprintf('Sleeping for %d seconds so consumer has run for %d seconds', $time, $minDuration),
                OutputInterface::VERBOSITY_VERBOSE
            );
            sleep($time);
        }

        $this->output('Shutting down consumer');
    }

    /**
     * @param string $name
     *
     * @return MessageProviderInterface
     */
    protected function getMessageProvider($name)
    {
        return $this->getContainer()->get(sprintf('tree_house.queue.provider.%s', $name));
    }

    /**
     * @param string $name
     *
     * @return ProcessorInterface
     */
    protected function getProcessor($name)
    {
        return $this->getContainer()->get(sprintf('tree_house.queue.processor.%s', $name));
    }

    /**
     * Dispatches flush event
     */
    protected function flush()
    {
        $this->output('Batch completed', OutputInterface::VERBOSITY_VERBOSE);

        $this->getContainer()->get('event_dispatcher')->dispatch(QueueEvents::FLUSH);
    }

    /**
     * @param string  $message
     * @param integer $verbosity
     */
    protected function output($message, $verbosity = OutputInterface::VERBOSITY_NORMAL)
    {
        if ($this->output->getVerbosity() < $verbosity) {
            return;
        }

        $this->output->writeln($message);
    }

    /**
     * @param Message $message
     * @param integer $offset
     *
     * @return string
     */
    protected function getPayloadOutput(Message $message, $offset = 0)
    {
        $payload = $message->getBody();

        $width = (int) $this->getApplication()->getTerminalDimensions()[0] - $offset;
        if ($width > 0 && mb_strwidth($payload, 'utf8') > $width) {
            $payload = mb_substr($payload, 0, $width - 3) . '...';
        }

        return $payload;
    }
}
