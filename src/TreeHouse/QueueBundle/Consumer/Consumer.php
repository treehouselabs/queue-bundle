<?php

namespace TreeHouse\QueueBundle\Consumer;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TreeHouse\Queue\Consumer\ConsumerInterface;
use TreeHouse\Queue\Event\ConsumeEvent;
use TreeHouse\Queue\Event\ConsumeExceptionEvent;
use TreeHouse\Queue\QueueEvents;
use TreeHouse\QueueBundle\Consumer\Limiter\LimiterInterface;
use TreeHouse\QueueBundle\Consumer\Limiter\LimitReachedException;

class Consumer implements EventSubscriberInterface
{
    /**
     * @var ConsumerInterface
     */
    private $consumer;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var string
     */
    private $consumerTag;

    /**
     * @var LimiterInterface[]
     */
    private $limiters = [];

    /**
     * @var int
     */
    private $startTime;

    /**
     * @var int
     */
    private $minDuration = 15;

    /**
     * @var int
     */
    private $processed = 0;

    /**
     * @var int
     */
    private $batchSize = 25;

    /**
     * @var int
     */
    private $coolDownTime = 0;

    /**
     * @param ConsumerInterface $consumer
     * @param OutputInterface   $output
     * @param string           $consumerTag
     */
    public function __construct(ConsumerInterface $consumer, OutputInterface $output, $consumerTag = null)
    {
        $this->consumer = $consumer;
        $this->output = $output;
        $this->consumerTag = $consumerTag;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            QueueEvents::CONSUME_MESSAGE => 'onConsumeMessage',
            QueueEvents::CONSUMED_MESSAGE => 'onMessageConsumed',
            QueueEvents::CONSUME_EXCEPTION => 'onConsumeException',
        ];
    }

    /**
     * @param LimiterInterface $limiter
     */
    public function addLimiter(LimiterInterface $limiter)
    {
        $this->limiters[] = $limiter;
    }

    /**
     * @param int $duration
     *
     * @return $this
     */
    public function mustRunFor($duration)
    {
        $this->minDuration = $duration;

        return $this;
    }

    /**
     * @param int $batchSize
     *
     * @return $this
     */
    public function flushAfter($batchSize)
    {
        $this->batchSize = $batchSize;

        return $this;
    }

    /**
     * @param int $coolDownTime
     *
     * @return $this
     */
    public function waitBetweenMessages($coolDownTime)
    {
        $this->coolDownTime = $coolDownTime;

        return $this;
    }

    /**
     * @return int
     */
    public function getProcessed()
    {
        return $this->processed;
    }

    /**
     * @return int
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * @return int
     */
    public function getDuration()
    {
        return time() - $this->startTime;
    }

    /**
     * @throws \Exception
     */
    public function consume()
    {
        $this->consumer->getEventDispatcher()->addSubscriber($this);

        $this->startTime = time();

        try {
            $this->consumer->consume($this->consumerTag);

            $this->shutdown();
        } catch (\Exception $e) {
            $this->output->writeln(
                sprintf('Uncaught %s thrown by consumer, shutting down gracefully', get_class($e)),
                OutputInterface::VERBOSITY_VERBOSE
            );

            $this->shutdown();

            throw $e;
        }
    }

    /**
     * @param ConsumeEvent $event
     */
    public function onConsumeMessage(ConsumeEvent $event)
    {
        $envelope = $event->getEnvelope();
        $fullPayload = $this->output->getVerbosity() > OutputInterface::VERBOSITY_VERBOSE;

        $this->output->writeln(
            sprintf(
                '<comment>[%s]</comment> Processing payload <info>%s</info>',
                $envelope->getDeliveryTag(),
                $this->getPayloadOutput($envelope->getBody(), $fullPayload)
            )
        );
    }

    /**
     * @param ConsumeEvent $event
     */
    public function onMessageConsumed(ConsumeEvent $event)
    {
        $envelope = $event->getEnvelope();

        $this->output->writeln(
            sprintf(
                '<comment>[%s]</comment> processed with result: <info>%s</info>',
                $envelope->getDeliveryTag(),
                json_encode($event->getResult())
            )
        );

        // see if batch is completed
        if (++$this->processed % $this->batchSize === 0) {
            $this->flush();
        }

        try {
            foreach ($this->limiters as $limiter) {
                $limiter->limitReached($this);
            }
        } catch (LimitReachedException $e) {
            $this->output->writeln(
                $e->getMessage(),
                OutputInterface::VERBOSITY_VERBOSE
            );

            $event->stopConsuming();
        }

        // cool down
        usleep($this->coolDownTime);
    }

    /**
     * @param ConsumeExceptionEvent $event
     */
    public function onConsumeException(ConsumeExceptionEvent $event)
    {
        $envelope = $event->getEnvelope();
        $exception = $event->getException();

        $this->output->writeln(
            sprintf(
                '<comment>[%s]</comment> raised <info>%s</info>: <error>"%s"</error>',
                $envelope->getDeliveryTag(),
                get_class($exception),
                $exception->getMessage()
            )
        );
    }

    /**
     * @param string $payload
     * @param bool   $fullPayload
     *
     * @return string
     */
    private function getPayloadOutput($payload, $fullPayload = false)
    {
        if ($fullPayload === true) {
            return $payload;
        }

        $maxWidth = 100;
        if (mb_strwidth($payload, 'utf8') > $maxWidth) {
            $payload = mb_substr($payload, 0, $maxWidth - 10) . '...';
        }

        return $payload;
    }

    /**
     * Dispatches flush event.
     */
    private function flush()
    {
        $this->output->writeln(
            'Batch completed, flushing',
            OutputInterface::VERBOSITY_VERBOSE
        );

        $this->consumer->getEventDispatcher()->dispatch(QueueEvents::CONSUME_FLUSH);
    }

    /**
     * Shutdown procedure
     */
    private function shutdown()
    {
        $this->output->writeln('Shutting down consumer');

        // flush remaining changes
        $this->flush();

        // cancel the subscription with the queue
        $this->consumer->cancel($this->consumerTag);

        // make sure consumer doesn't quit to quickly, or supervisor will mark it as a failed restart,
        // and putting the process in FATAL state.
        $duration = $this->getDuration();
        if ($duration < $this->minDuration) {
            $remaining = $this->minDuration - $duration;

            $this->output->writeln(
                sprintf('Sleeping for %d seconds so consumer has run for %d seconds', $remaining, $this->minDuration),
                OutputInterface::VERBOSITY_VERBOSE
            );

            sleep($remaining);
        }
    }
}
