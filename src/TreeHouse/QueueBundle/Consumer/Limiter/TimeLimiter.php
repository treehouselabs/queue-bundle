<?php

namespace TreeHouse\QueueBundle\Consumer\Limiter;

use TreeHouse\QueueBundle\Consumer\Consumer;

class TimeLimiter implements LimiterInterface
{
    /**
     * @var int
     */
    private $maxTime;

    /**
     * @param int $maxTime
     */
    public function __construct($maxTime)
    {
        $this->maxTime = (int) $maxTime;
    }

    /**
     * @inheritdoc
     */
    public function limitReached(Consumer $consumer)
    {
        if ($consumer->getDuration() >= $this->maxTime) {
            throw LimitReachedException::withMessage(
                sprintf('Maximum execution time of %ds reached', $this->maxTime)
            );
        }
    }
}
