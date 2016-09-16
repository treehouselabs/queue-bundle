<?php

namespace TreeHouse\QueueBundle\Consumer\Limiter;

use TreeHouse\QueueBundle\Consumer\Consumer;

interface LimiterInterface
{
    /**
     * @param Consumer $consumer
     *
     * @throws LimitReachedException
     */
    public function limitReached(Consumer $consumer);
}
