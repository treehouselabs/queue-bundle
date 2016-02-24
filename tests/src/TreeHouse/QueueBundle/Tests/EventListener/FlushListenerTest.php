<?php

namespace TreeHouse\QueueBundle\Tests\EventListener;

use Mockery\MockInterface;
use TreeHouse\QueueBundle\EventListener\FlushListener;
use TreeHouse\QueueBundle\Flusher\FlushingInterface;

class FlushListenerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_can_call_flushers()
    {
        /** @var FlushingInterface|MockInterface $flusher */
        $flusher = \Mockery::mock(FlushingInterface::class);
        $flusher->shouldReceive('flush')->once();

        $listener = new FlushListener();
        $listener->addFlusher($flusher);

        $listener->onFlush();
    }
}
