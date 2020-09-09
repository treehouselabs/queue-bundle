<?php

namespace TreeHouse\QueueBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\EventListener\FlushListener;
use TreeHouse\QueueBundle\Flusher\FlushingInterface;

class FlushListenerTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_call_flushers()
    {
        /** @var FlushingInterface $flusher */
        $flusher = $this->prophesize(FlushingInterface::class);
        $flusher->flush()->shouldBeCalledOnce();

        $listener = new FlushListener();
        $listener->addFlusher($flusher->reveal());

        $listener->onFlush();
    }
}
