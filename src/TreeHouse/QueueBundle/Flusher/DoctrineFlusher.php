<?php

namespace TreeHouse\QueueBundle\Flusher;

use Doctrine\Persistence\ManagerRegistry;

class DoctrineFlusher implements FlushingInterface
{
    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    /**
     * @param ManagerRegistry $doctrine
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * @inheritdoc
     */
    public function flush()
    {
        $this->doctrine->getManager()->flush();
    }
}
