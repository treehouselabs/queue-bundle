<?php

namespace TreeHouse\QueueBundle\Consumer\Limiter;

use TreeHouse\QueueBundle\Consumer\Consumer;

class MemoryLimiter implements LimiterInterface
{
    /**
     * @var int
     */
    private $maxMemory;

    /**
     * @param int $maxMemory
     */
    public function __construct($maxMemory)
    {
        $this->maxMemory = (int) $maxMemory;
    }

    /**
     * @inheritdoc
     */
    public function limitReached(Consumer $consumer)
    {
        if (memory_get_usage(true) > $this->maxMemory) {
            throw LimitReachedException::withMessage(
                sprintf('Memory peak of %dMB reached', $this->maxMemory / 1024 / 1024)
            );
        }
    }
}
