<?php

namespace TreeHouse\QueueBundle\EventListener;

use TreeHouse\QueueBundle\Flusher\FlushingInterface;

class FlushListener
{
    /**
     * @var FlushingInterface[]
     */
    protected $flushers = [];

    /**
     * @param FlushingInterface $flusher
     */
    public function addFlusher(FlushingInterface $flusher)
    {
        $this->flushers[] = $flusher;
    }

    /**
     * Calls all flushers
     */
    public function onFlush()
    {
        foreach ($this->flushers as $flusher) {
            $flusher->flush();
        }
    }
}
