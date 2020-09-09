<?php

namespace TreeHouse\QueueBundle\Tests\DependencyInjection\Compiler;

use Doctrine\Persistence\ManagerRegistry;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TreeHouse\QueueBundle\DependencyInjection\Compiler\RegisterFlushersPass;
use TreeHouse\QueueBundle\EventListener\FlushListener;
use TreeHouse\QueueBundle\Flusher\FlushingInterface;

class RegisterFlushersPassTest extends AbstractCompilerPassTestCase
{
    /**
     * @test
     * @dataProvider getDoctrineServices
     *
     * @param string $doctrine
     */
    public function it_registers_doctrine_flushers_automatically($doctrine)
    {
        $this->registerService('tree_house.queue.event_listener.flush', FlushListener::class);
        $this->registerService($doctrine, ManagerRegistry::class);

        $this->compile();

        $flusherId = sprintf('tree_house.queue.flusher.%s', $doctrine);

        $this->assertContainerBuilderHasService($flusherId);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'tree_house.queue.event_listener.flush',
            'addFlusher',
            [new Reference($flusherId)]
        );
    }

    /**
     * @return array
     */
    public function getDoctrineServices()
    {
        return [
            ['doctrine'],
            ['doctrine_mongodb'],
        ];
    }

    /**
     * @test
     */
    public function it_registers_tagged_flushers()
    {
        $this->registerService('tree_house.queue.event_listener.flush', FlushListener::class);

        $definition = new Definition(FlushingInterface::class);
        $definition->addTag('tree_house.queue.flusher');

        $this->setDefinition('flusher', $definition);
        $this->compile();

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'tree_house.queue.event_listener.flush',
            'addFlusher',
            [new Reference('flusher')]
        );
    }

    /**
     * @inheritdoc
     */
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RegisterFlushersPass());
    }
}
