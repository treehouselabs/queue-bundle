<?php

namespace TreeHouse\QueueBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DoctrineSerializerPass implements CompilerPassInterface
{
    /**
     * @inheritdoc
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('doctrine')) {
            $container->removeDefinition('tree_house.queue.serializer.doctrine');

            return;
        }
    }
}
