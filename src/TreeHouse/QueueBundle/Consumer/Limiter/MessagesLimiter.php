<?php

namespace TreeHouse\QueueBundle\Consumer\Limiter;

use TreeHouse\QueueBundle\Consumer\Consumer;

class MessagesLimiter implements LimiterInterface
{
    /**
     * @var int
     */
    private $limit;

    /**
     * @param int $limit
     */
    public function __construct($limit)
    {
        $this->limit = (int) $limit;
    }

    /**
     * @inheritdoc
     */
    public function limitReached(Consumer $consumer)
    {
        if ($consumer->getProcessed() >= $this->limit) {
            throw LimitReachedException::withMessage(
                sprintf('Maximum number of messages consumed (%d)', $this->limit)
            );
        }
    }
}
