<?php

namespace TreeHouse\QueueBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use TreeHouse\QueueBundle\DependencyInjection\Compiler\DoctrineSerializerPass;
use TreeHouse\QueueBundle\DependencyInjection\Compiler\RegisterFlushersPass;

class TreeHouseQueueBundle extends Bundle
{
    /**
     * @inheritdoc
     */
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new DoctrineSerializerPass());
        $container->addCompilerPass(new RegisterFlushersPass());
    }
}
