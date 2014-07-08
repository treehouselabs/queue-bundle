<?php

namespace TreeHouse\QueueBundle\Flusher;

interface FlushingInterface
{
    /**
     * @return void
     */
    public function flush();
}
