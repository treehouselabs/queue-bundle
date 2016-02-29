<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\Queue\Consumer\ConsumerInterface;
use TreeHouse\Queue\Event\ConsumeEvent;
use TreeHouse\Queue\Event\ConsumeExceptionEvent;
use TreeHouse\Queue\QueueEvents;

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
        $name = $input->getArgument('queue');
        $batchSize = intval($input->getOption('batch-size'));
        $wait = intval($input->getOption('wait'));
        $limit = intval($input->getOption('limit'));
        $maxMemory = intval($input->getOption('max-memory')) * 1024 * 1024;
        $maxTime = intval($input->getOption('max-time'));

        $startTime = time();
        $minDuration = 15;

        $this->output = $output;
        $this->output(sprintf('Consuming from <info>%s</info> queue', $name));

        $consumer = $this->getConsumer($name);
        $dispatcher = $consumer->getEventDispatcher();
        $dispatcher->addListener(QueueEvents::CONSUME_MESSAGE, [$this, 'onConsumeMessage']);
        $dispatcher->addListener(QueueEvents::CONSUMED_MESSAGE, [$this, 'onMessageConsumed']);
        $dispatcher->addListener(QueueEvents::CONSUME_EXCEPTION, [$this, 'onConsumeException']);

        $processed = 0;
        while (true) {
            try {
                $consumer->consume();
            } catch (\Exception $e) {
                $this->output(
                    sprintf('Uncaught %s thrown by consumer, shutting down gracefully', get_class($e)),
                    $output::VERBOSITY_VERBOSE
                );

                $this->shutdown($consumer, $startTime, $minDuration);

                throw $e;
            }

            // see if batch is completed
            if (++$processed % $batchSize === 0) {
                $this->output('Batch completed', OutputInterface::VERBOSITY_VERBOSE);
                $this->flush($consumer);
            }

            // check for maximum number of processed messages
            if (($limit > 0) && ($processed >= $limit)) {
                $this->output(
                    sprintf('Maximum number of messages consumed (%d)', $limit),
                    $output::VERBOSITY_VERBOSE
                );

                break;
            }

            // check for max memory usage
            if (($maxMemory > 0) && memory_get_usage(true) > $maxMemory) {
                $this->output(
                    sprintf('Memory peak of %dMB reached', $maxMemory / 1024 / 1024),
                    $output::VERBOSITY_VERBOSE
                );

                break;
            }

            // check for execution time
            if (($maxTime > 0) && ((time() - $startTime) > $maxTime)) {
                $this->output(
                    sprintf('Maximum execution time of %ds reached', $maxTime),
                    $output::VERBOSITY_VERBOSE
                );

                break;
            }

            // cool down
            usleep($wait);
        }

        $this->shutdown($consumer, $startTime, $minDuration);
    }

    /**
     * @param ConsumeEvent $event
     */
    public function onConsumeMessage(ConsumeEvent $event)
    {
        $envelope = $event->getEnvelope();
        $verbose = $this->output->getVerbosity() > OutputInterface::VERBOSITY_VERBOSE;

        $this->output(
            sprintf(
                '<comment>[%s]</comment> Processing payload <info>%s</info>',
                $envelope->getDeliveryTag(),
                $this->getPayloadOutput($envelope->getBody(), 20, $verbose)
            )
        );
    }

    /**
     * @param ConsumeEvent $event
     */
    public function onMessageConsumed(ConsumeEvent $event)
    {
        $envelope = $event->getEnvelope();

        $this->output(
            sprintf(
                '<comment>[%s]</comment> processed with result: <info>%s</info>',
                $envelope->getDeliveryTag(),
                json_encode($event->getResult())
            )
        );
    }

    /**
     * @param ConsumeExceptionEvent $event
     */
    public function onConsumeException(ConsumeExceptionEvent $event)
    {
        $envelope = $event->getEnvelope();
        $exception = $event->getException();

        $this->output(
            sprintf(
                '<comment>[%s]</comment> raised <info>%s</info>: <error>%s</error>',
                $envelope->getDeliveryTag(),
                get_class($exception),
                $exception->getMessage()
            )
        );
    }

    /**
     * @param string $name
     *
     * @return ConsumerInterface
     */
    private function getConsumer($name)
    {
        return $this->getContainer()->get(sprintf('tree_house.queue.consumer.%s', $name));
    }

    /**
     * Dispatches flush event.
     *
     * @param ConsumerInterface $consumer
     */
    private function flush(ConsumerInterface $consumer)
    {
        $consumer->getEventDispatcher()->dispatch(QueueEvents::CONSUME_FLUSH);
    }

    /**
     * @param string $message
     * @param int    $threshold
     */
    private function output($message, $threshold = OutputInterface::VERBOSITY_NORMAL)
    {
        if ($this->output->getVerbosity() < $threshold) {
            return;
        }

        $this->output->writeln($message);
    }

    /**
     * @param string $payload
     * @param int    $offset
     * @param bool   $fullPayload
     *
     * @return string
     */
    private function getPayloadOutput($payload, $offset = 0, $fullPayload = false)
    {
        if ($fullPayload === true) {
            return $payload;
        }

        $width = (int) $this->getApplication()->getTerminalDimensions()[0] - $offset;
        if ($width > 0 && mb_strwidth($payload, 'utf8') > $width) {
            $payload = mb_substr($payload, 0, $width - 10) . '...';
        }

        return $payload;
    }

    /**
     * @param ConsumerInterface $consumer
     * @param int $startTime
     * @param int $minDuration
     */
    private function shutdown($consumer, $startTime, $minDuration)
    {
        $this->output('Shutting down consumer');

        // flush remaining changes
        $this->flush($consumer);

        // make sure consumer doesn't quit to quickly, or supervisor will mark it as a failed restart,
        // and putting the process in FATAL state.
        $duration = time() - $startTime;
        if ($duration < $minDuration) {
            $time = $minDuration - $duration;

            $this->output(
                sprintf('Sleeping for %d seconds so consumer has run for %d seconds', $time, $minDuration),
                OutputInterface::VERBOSITY_VERBOSE
            );

            sleep($time);
        }
    }
}
