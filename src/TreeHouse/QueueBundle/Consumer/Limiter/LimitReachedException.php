<?php

namespace TreeHouse\QueueBundle\Consumer\Limiter;

class LimitReachedException extends \RuntimeException
{
    /**
     * @param string $message
     *
     * @return LimitReachedException
     */
    public static function withMessage($message)
    {
        return new self($message);
    }
}
