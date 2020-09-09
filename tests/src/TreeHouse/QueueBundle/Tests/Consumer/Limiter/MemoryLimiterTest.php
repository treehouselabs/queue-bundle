<?php

namespace TreeHouse\QueueBundle\Tests\Consumer\Limiter;

use PHPUnit\Framework\TestCase;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\Consumer\Limiter\LimitReachedException;
use TreeHouse\QueueBundle\Consumer\Limiter\MemoryLimiter;

class MemoryLimiterTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_reach_a_limit()
    {
        $limiter = new MemoryLimiter(1);

        /** @var Consumer $consumer */
        $consumer = $this->prophesize(Consumer::class);

        $this->expectException(LimitReachedException::class);

        $limiter->limitReached($consumer->reveal());
    }

    /**
     * @test
     */
    public function it_must_reach_a_limit()
    {
        $limiter = new MemoryLimiter(PHP_INT_MAX);

        /** @var Consumer $consumer */
        $consumer = $this->prophesize(Consumer::class);

        $this->assertNull($limiter->limitReached($consumer->reveal()));
    }
}
