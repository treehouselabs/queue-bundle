<?php

namespace TreeHouse\QueueBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use TreeHouse\Queue\Message\Message;

class ConsumeEvent extends Event
{
    /**
     * @var Message
     */
    protected $message;

    /**
     * @param Message $message
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    /**
     * @return Message
     */
    public function getMessage()
    {
        return $this->message;
    }
}
