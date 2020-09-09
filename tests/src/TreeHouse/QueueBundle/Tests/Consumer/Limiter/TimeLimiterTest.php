<?php

namespace TreeHouse\QueueBundle\Tests\Consumer\Limiter;

use PHPUnit\Framework\TestCase;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\Consumer\Limiter\LimitReachedException;
use TreeHouse\QueueBundle\Consumer\Limiter\TimeLimiter;

class TimeLimiterTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_reach_a_limit()
    {
        $limiter = new TimeLimiter(10);

        /** @var Consumer $consumer */
        $consumer = $this->prophesize(Consumer::class);
        $consumer->getDuration()->willReturn(10);

        $this->expectException(LimitReachedException::class);

        $limiter->limitReached($consumer->reveal());
    }

    /**
     * @test
     */
    public function it_must_reach_a_limit()
    {
        $limiter = new TimeLimiter(10);

        /** @var Consumer $consumer */
        $consumer = $this->prophesize(Consumer::class);
        $consumer->getDuration()->willReturn(9);

        $this->assertNull($limiter->limitReached($consumer->reveal()));
    }
}
