<?php

namespace TreeHouse\QueueBundle\Tests\DependencyInjection\Compiler;

use Doctrine\Common\Persistence\ManagerRegistry;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TreeHouse\Queue\Message\Serializer\DoctrineSerializer;
use TreeHouse\QueueBundle\DependencyInjection\Compiler\DoctrineSerializerPass;

class DoctrineSerializerPassTest extends AbstractCompilerPassTestCase
{
    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        parent::setUp();

        $this->registerService('tree_house.queue.serializer.doctrine', DoctrineSerializer::class);
    }

    /**
     * @test
     */
    public function it_removes_serializer_when_doctrine_is_missing()
    {
        $this->compile();

        $this->assertContainerBuilderNotHasService('tree_house.queue.serializer.doctrine');
    }

    /**
     * @test
     */
    public function it_leaves_serializer_when_doctrine_is_present()
    {
        $this->registerService('doctrine', ManagerRegistry::class);
        $this->compile();

        $this->assertContainerBuilderHasService('tree_house.queue.serializer.doctrine');
    }

    /**
     * @inheritdoc
     */
    protected function registerCompilerPass(ContainerBuilder $container)
    {
        $container->addCompilerPass(new DoctrineSerializerPass());
    }
}
