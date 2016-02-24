<?php

namespace TreeHouse\QueueBundle\Tests\Flusher;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\ObjectManager;
use Mockery\MockInterface;
use TreeHouse\QueueBundle\Flusher\DoctrineFlusher;
use TreeHouse\QueueBundle\Flusher\FlushingInterface;

class DoctrineFlusherTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_can_be_constructed()
    {
        $doctrine = $this->createDoctrineMock();
        $flusher = new DoctrineFlusher($doctrine);

        $this->assertInstanceOf(FlushingInterface::class, $flusher);
    }

    /**
     * @test
     */
    public function it_can_flush_changes()
    {
        $manager = \Mockery::mock(ObjectManager::class);
        $manager->shouldReceive('flush')->once();

        $doctrine = $this->createDoctrineMock();
        $doctrine->shouldReceive('getManager')->andReturn($manager);

        $flusher = new DoctrineFlusher($doctrine);
        $flusher->flush();
    }

    /**
     * @return MockInterface|ManagerRegistry
     */
    private function createDoctrineMock()
    {
        return \Mockery::mock(ManagerRegistry::class);
    }
}
