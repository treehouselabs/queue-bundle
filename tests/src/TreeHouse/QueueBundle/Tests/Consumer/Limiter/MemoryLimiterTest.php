<?php

namespace TreeHouse\QueueBundle\Tests\Consumer\Limiter;

use Mockery as Mock;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\Consumer\Limiter\LimitReachedException;
use TreeHouse\QueueBundle\Consumer\Limiter\MemoryLimiter;

class MemoryLimiterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_can_reach_a_limit()
    {
        $limiter = new MemoryLimiter(1);
        $consumer = Mock::mock(Consumer::class);

        $this->expectException(LimitReachedException::class);

        $limiter->limitReached($consumer);
    }

    /**
     * @test
     */
    public function it_must_reach_a_limit()
    {
        $limiter = new MemoryLimiter(PHP_INT_MAX);
        $consumer = Mock::mock(Consumer::class);

        $this->assertNull(
            $limiter->limitReached($consumer)
        );
    }
}
