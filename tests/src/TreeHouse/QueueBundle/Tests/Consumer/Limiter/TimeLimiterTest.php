<?php

namespace TreeHouse\QueueBundle\Tests\Consumer\Limiter;

use Mockery as Mock;
use TreeHouse\QueueBundle\Consumer\Consumer;
use TreeHouse\QueueBundle\Consumer\Limiter\LimitReachedException;
use TreeHouse\QueueBundle\Consumer\Limiter\TimeLimiter;

class TimeLimiterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_can_reach_a_limit()
    {
        $limiter = new TimeLimiter(10);
        $consumer = Mock::mock(Consumer::class);
        $consumer->shouldReceive('getDuration')->andReturn(10);

        $this->expectException(LimitReachedException::class);

        $limiter->limitReached($consumer);
    }

    /**
     * @test
     */
    public function it_must_reach_a_limit()
    {
        $limiter = new TimeLimiter(10);
        $consumer = Mock::mock(Consumer::class);
        $consumer->shouldReceive('getDuration')->andReturn(9);

        $this->assertNull(
            $limiter->limitReached($consumer)
        );
    }
}
