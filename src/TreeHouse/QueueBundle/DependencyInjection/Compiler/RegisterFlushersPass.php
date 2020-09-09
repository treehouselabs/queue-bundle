<?php

namespace TreeHouse\QueueBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class RegisterFlushersPass implements CompilerPassInterface
{
    /**
     * @inheritdoc
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('tree_house.queue.event_listener.flush')) {
            return;
        }

        $listener = $container->getDefinition('tree_house.queue.event_listener.flush');

        foreach (['doctrine', 'doctrine_mongodb'] as $id) {
            if ($container->hasDefinition($id)) {
                $flusherId = sprintf('tree_house.queue.flusher.%s', $id);

                $definition = new ChildDefinition('tree_house.queue.flusher.doctrine_abstract');
                $definition->addArgument(new Reference($id));
                $container->setDefinition($flusherId, $definition);

                $listener->addMethodCall('addFlusher', [new Reference($flusherId)]);
            }
        }

        foreach ($container->findTaggedServiceIds('tree_house.queue.flusher') as $id => [$tag]) {
            $listener->addMethodCall('addFlusher', [new Reference($id)]);
        }
    }
}
