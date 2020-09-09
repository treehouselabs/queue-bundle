<?php

namespace TreeHouse\QueueBundle\Tests\Flusher;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use TreeHouse\QueueBundle\Flusher\DoctrineFlusher;
use TreeHouse\QueueBundle\Flusher\FlushingInterface;

class DoctrineFlusherTest extends TestCase
{
    /**
     * @test
     */
    public function it_can_be_constructed()
    {
        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->prophesize(ManagerRegistry::class);
        $flusher = new DoctrineFlusher($doctrine->reveal());

        $this->assertInstanceOf(FlushingInterface::class, $flusher);
    }

    /**
     * @test
     */
    public function it_can_flush_changes()
    {
        /** @var ObjectManager $manager */
        $manager = $this->prophesize(ObjectManager::class);
        $manager->flush()->shouldBeCalledOnce();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $this->prophesize(ManagerRegistry::class);
        $doctrine->getManager()->willReturn($manager->reveal());

        $flusher = new DoctrineFlusher($doctrine->reveal());
        $flusher->flush();
    }
}
