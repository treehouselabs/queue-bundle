<?php

namespace TreeHouse\QueueBundle\Tests\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use TreeHouse\Queue\Processor\Retry\RetryProcessor;
use TreeHouse\QueueBundle\DependencyInjection\TreeHouseQueueExtension;

class TreeHouseQueueExtensionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        if (!extension_loaded('amqp')) {
            $this->markTestSkipped('AMQP extension not loaded');
        }
    }

    public function testDriverParameters()
    {
        $container = $this->getContainer('complete.yml');

        $this->assertTrue($container->hasParameter('tree_house.queue.driver'));

        $driver = $container->getParameter('tree_house.queue.driver');
        $this->assertEquals('amqp', $driver);

        $classes = ['connection', 'channel', 'exchange', 'queue', 'provider', 'publisher', 'factory'];
        foreach ($classes as $class) {
            $this->assertEquals(
                $container->getParameter(sprintf('tree_house.queue.driver.%s.%s.class', $driver, $class)),
                $container->getParameter(sprintf('tree_house.queue.%s.class', $class))
            );
        }
    }

    public function testFixedDriverParameters()
    {
        $fixedClass = 'foo\\bar\\baz';

        $container = $this->getContainer('complete.yml', ['tree_house.queue.publisher.class' => $fixedClass]);

        $this->assertEquals(
            $fixedClass,
            $container->getParameter('tree_house.queue.publisher.class'),
            'Don\'t override driver parameter'
        );
    }

    /**
     * @dataProvider getConnectionConfigurationFixtures
     *
     * @param string $fixtureName
     * @param array  $expected
     */
    public function testConnectionConfiguration($fixtureName, array $expected)
    {
        $container = $this->getContainer(sprintf('%s.yml', $fixtureName));

        foreach ($expected as $name => $conn) {
            $connName = sprintf('tree_house.queue.connection.%s', $name);
            $this->assertTrue($container->hasDefinition($connName));

            $definition = $container->getDefinition($connName);

            // assert the constructor args
            $index = 0;
            foreach ($conn as $value) {
                $this->assertSame($value, $definition->getArgument($index++));
            }
        }
    }

    /**
     * @return array Fixtures for testing different connection configurations
     */
    public function getConnectionConfigurationFixtures()
    {
        return [
            ['connection1', ['default' => ['host' => 'localhost']]],
            ['connection2', ['default' => ['host' => 'localhost']]],
            ['connection3', ['conn1' => ['host' => 'localhost'], 'conn2' => ['host' => 'rabbitmqhost', 'port' => 123]]],
            ['connection4', ['conn1' => ['host' => 'localhost'], 'conn2' => ['host' => 'rabbitmqhost', 'port' => 123]]],
        ];
    }

    public function testConnectionDefinition()
    {
        $container = $this->getContainer('complete.yml');

        // assert that both connections are created
        $this->assertTrue($container->hasDefinition('tree_house.queue.connection.conn1'));
        $this->assertTrue($container->hasDefinition('tree_house.queue.connection.conn2'));

        // assert the class
        $connection = $container->getDefinition('tree_house.queue.connection.conn1');
        $this->assertEquals(
            $container->getParameter('tree_house.queue.driver.amqp.connection.class'),
            $connection->getClass()
        );

        // assert the constructor args
        $this->assertEquals('localhost', $connection->getArgument(0));
        $this->assertEquals(5672,        $connection->getArgument(1));
        $this->assertEquals('guest',     $connection->getArgument(2));
        $this->assertEquals('guest',     $connection->getArgument(3));
        $this->assertEquals('/',         $connection->getArgument(4));

        // assert constructor args for other connection
        $connection = $container->getDefinition('tree_house.queue.connection.conn2');
        $this->assertEquals('rabbitmqhost', $connection->getArgument(0));
        $this->assertEquals(123,            $connection->getArgument(1));
        $this->assertEquals('foo_user',     $connection->getArgument(2));
        $this->assertEquals('foo_pass',     $connection->getArgument(3));
        $this->assertEquals('/foo',         $connection->getArgument(4));

        // assert that a channel is also created
        $channel = $container->getDefinition('tree_house.queue.channel.conn1');
        $this->assertEquals(
            $container->getParameter('tree_house.queue.driver.amqp.channel.class'),
            $channel->getClass()
        );

        $this->assertInstanceOf(Reference::class, $channel->getArgument(0));
        $this->assertEquals('tree_house.queue.connection.conn1', (string) $channel->getArgument(0));
    }

    public function testFirstConnectionIsDefault()
    {
        $container = $this->getContainer('connections_no_default.yml');

        $this->assertTrue($container->hasAlias('tree_house.queue.default_connection'));
        $this->assertEquals('tree_house.queue.connection.conn1', $container->getAlias('tree_house.queue.default_connection'));
    }

    public function testDefaultConnectionSet()
    {
        $container = $this->getContainer('connections_default.yml');

        $this->assertEquals('tree_house.queue.connection.conn2', $container->getAlias('tree_house.queue.default_connection'));
    }

    public function testPublisherConfiguration()
    {
        $container = $this->getContainer('complete.yml');

        // test the exchange
        $this->assertTrue($container->hasDefinition('tree_house.queue.exchange.process1'));
        $exchange = $container->getDefinition('tree_house.queue.exchange.process1');
        $this->assertEquals($container->getParameter('tree_house.queue.exchange.class'), $exchange->getClass());

        // test the serializer
        $this->assertTrue($container->hasAlias('tree_house.queue.serializer.process2'));
        $serializer = $container->getAlias('tree_house.queue.serializer.process2');
        $this->assertEquals('tree_house.queue.serializer.json', (string) $serializer);

        // test the composer
        $this->assertTrue($container->hasDefinition('tree_house.queue.composer.process1'));
        $composer = $container->getDefinition('tree_house.queue.composer.process1');
        $this->assertEquals($container->getParameter('tree_house.queue.composer.default.class'), $composer->getClass());

        // test the publisher
        $this->assertTrue($container->hasDefinition('tree_house.queue.publisher.process2'));
        $publisher = $container->getDefinition('tree_house.queue.publisher.process2');
        $this->assertEquals($container->getParameter('tree_house.queue.publisher.class'), $publisher->getClass());
    }

    public function testPublisherDefinition()
    {
        $container = $this->getContainer('complete.yml');

        // test the exchange
        $exchange = $container->getDefinition('tree_house.queue.exchange.process2');
        $this->assertEquals('process2', $exchange->getArgument(1));
        $this->assertEquals(AMQP_EX_TYPE_TOPIC, $exchange->getArgument(2));
        $this->assertEquals(AMQP_PASSIVE | AMQP_DURABLE, $exchange->getArgument(3));
        $this->assertEquals(['x-ha-policy' => 'all'], $exchange->getArgument(4));
    }

    public function testExchangeConnectionAlias()
    {
        $container = $this->getContainer('complete.yml');

        // test that the the exchange refers to a different channel
        $exchange = $container->getDefinition('tree_house.queue.exchange.process2');

        /** @var Reference $channel */
        $channel = $exchange->getArgument(0);
        $this->assertEquals('tree_house.queue.channel.process2', (string) $channel);

        // also test that an alias to this channel is created for our exchange name
        $this->assertTrue($container->hasAlias('tree_house.queue.channel.process2'));
        $this->assertEquals('tree_house.queue.channel.conn2', (string) $container->getAlias('tree_house.queue.channel.process2'));
    }

    public function testConsumerConfiguration()
    {
        $container = $this->getContainer('complete.yml');

        // test the queue
        $this->assertTrue($container->hasDefinition('tree_house.queue.queue.process1'));
        $queue = $container->getDefinition('tree_house.queue.queue.process1');
        $this->assertEquals($container->getParameter('tree_house.queue.queue.class'), $queue->getClass());

        $queue = $container->getDefinition('tree_house.queue.queue.process2');
        $this->assertEquals([['bind', ['xchg1', 'foo', []]]], $queue->getMethodCalls());

        // test the provider
        $this->assertTrue($container->hasDefinition('tree_house.queue.provider.process2'));
        $provider = $container->getDefinition('tree_house.queue.provider.process2');
        $this->assertEquals($container->getParameter('tree_house.queue.provider.class'), $provider->getClass());

        // test the processor
        $this->assertTrue($container->hasDefinition('tree_house.queue.processor.process2'));
        $processor = $container->getDefinition('tree_house.queue.processor.process2');
        $this->assertEquals(RetryProcessor::class, $processor->getClass());

        // test the consumer
        $this->assertTrue($container->hasDefinition('tree_house.queue.consumer.process2'));
        /** @var DefinitionDecorator $consumer */
        $consumer = $container->getDefinition('tree_house.queue.consumer.process2');
        $this->assertInstanceOf(DefinitionDecorator::class, $consumer);
        $this->assertEquals('tree_house.queue.consumer.prototype', $consumer->getParent());
    }

    public function testQueueConfiguration()
    {
        $container = $this->getContainer('complete.yml');

        $this->assertTrue($container->hasDefinition('tree_house.queue.queue.q1'));
        $queue = $container->getDefinition('tree_house.queue.queue.q1');

        $this->assertEquals($container->getParameter('tree_house.queue.queue.class'), $queue->getClass());
        $this->assertEquals('tree_house.queue.channel.conn1', (string) $queue->getArgument(0));
        $this->assertEquals('q1', $queue->getArgument(1));
        $this->assertEquals(AMQP_DURABLE, $queue->getArgument(2));
        $this->assertEquals(['x-match' => 'all'], $queue->getArgument(3));
        $this->assertEquals(
            [
                ['bind', ['xchg1', 'foo', []]],
                ['bind', ['xchg1', 'bar', []]],
            ],
            $queue->getMethodCalls()
        );

        $this->assertTrue($container->hasDefinition('tree_house.queue.queue.q2'));
        $queue = $container->getDefinition('tree_house.queue.queue.q2');

        $this->assertEquals('tree_house.queue.channel.conn2', (string) $queue->getArgument(0));
        $this->assertEquals(null, $queue->getArgument(1));
        $this->assertEquals(AMQP_PASSIVE | AMQP_EXCLUSIVE | AMQP_AUTODELETE, $queue->getArgument(2));
        $this->assertEquals([], $queue->getArgument(3));
        $this->assertEquals(
            [
                ['bind', ['xchg1', 'foo', ['x-foo' => 'bar']]],
                ['bind', ['xchg1', 'bar', ['x-foo' => 'bar']]],
                ['bind', ['xchg2', 'foo', ['x-foo' => 'bar']]],
            ],
            $queue->getMethodCalls()
        );
    }

    private function getContainer($file, $parameters = [], $debug = false)
    {
        $container = new ContainerBuilder(new ParameterBag(array_merge($parameters, ['kernel.debug' => $debug])));
        $container->registerExtension(new TreeHouseQueueExtension());

        $locator = new FileLocator(__DIR__ . '/Fixtures');
        $loader = new YamlFileLoader($container, $locator);
        $loader->load($file);

        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        return $container;
    }
}
