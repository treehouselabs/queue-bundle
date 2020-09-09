<?php

namespace TreeHouse\QueueBundle\Tests\Consumer\Limiter;

use PHPUnit\Framework\TestCase;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\Consumer\Limiter\LimitReachedException;
use TreeHouse\QueueBundle\Consumer\Limiter\MessagesLimiter;

class MessagesLimiterTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_reach_a_limit()
    {
        $limiter = new MessagesLimiter(100);

        /** @var Consumer $consumer */
        $consumer = $this->prophesize(Consumer::class);
        $consumer->getProcessed()->willReturn(100);

        $this->expectException(LimitReachedException::class);

        $limiter->limitReached($consumer->reveal());
    }

    /**
     * @test
     */
    public function it_must_reach_a_limit()
    {
        $limiter = new MessagesLimiter(100);

        /** @var Consumer $consumer */
        $consumer = $this->prophesize(Consumer::class);
        $consumer->getProcessed()->willReturn(99);

        $this->assertNull($limiter->limitReached($consumer->reveal()));
    }
}
