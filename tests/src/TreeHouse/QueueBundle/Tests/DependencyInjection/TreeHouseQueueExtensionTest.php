<?php

namespace TreeHouse\QueueBundle\Tests\DependencyInjection;

use LogicException;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\DefinitionHasMethodCallConstraint;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TreeHouse\Queue\Amqp\ExchangeInterface;
use TreeHouse\Queue\Amqp\QueueInterface;
use TreeHouse\Queue\Processor\Retry\BackoffStrategy;
use TreeHouse\Queue\Processor\Retry\DeprioritizeStrategy;
use TreeHouse\Queue\Processor\Retry\RetryProcessor;
use TreeHouse\QueueBundle\DependencyInjection\TreeHouseQueueExtension;

class TreeHouseQueueExtensionTest extends AbstractExtensionTestCase
{
    private const CONNECTIONS_CONFIG = [
        'connections' => [
            'conn1' => [
                'host' => 'localhost',
            ],
        ],
    ];

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        if (!extension_loaded('amqp')) {
            $this->markTestSkipped('AMQP extension not loaded');
        }

        parent::setUp();
    }

    /**
     * @test
     */
    public function driver_parameters_are_set_up_correctly()
    {
        $config = [
            'driver' => 'amqp',
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderHasParameter('tree_house.queue.driver', 'amqp');
        $this->assertContainerBuilderHasAlias('tree_house.amqp.factory', 'tree_house.queue.driver.amqp.factory');

        $classes = ['connection', 'channel', 'exchange', 'queue', 'publisher', 'factory'];
        foreach ($classes as $class) {
            $expected = $this->container->getParameter(sprintf('tree_house.queue.driver.amqp.%s.class', $class));
            $this->assertContainerBuilderHasParameter(sprintf('tree_house.queue.%s.class', $class), $expected);
        }
    }

    /**
     * @test
     */
    public function driver_parameters_can_be_customized()
    {
        $publisherClass = 'foo\\bar\\baz';

        $this->setParameter('tree_house.queue.publisher.class', $publisherClass);
        $this->load(self::CONNECTIONS_CONFIG);

        $this->assertContainerBuilderHasParameter('tree_house.queue.publisher.class', $publisherClass);
    }

    /**
     * @test
     */
    public function it_should_remove_flush_listener_when_auto_flush_is_disabled()
    {
        $config = [
            'auto_flush' => false,
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderNotHasService('tree_house.queue.event_listener.queue');
    }

    /**
     * @test
     */
    public function it_should_create_connection_definitions()
    {
        $config = [
            'connections' => [
                'conn1' => [
                    'host' => 'localhosasdsadt',
                    'port' => 5672,
                    'user' => 'guest',
                    'pass' => 'guest',
                    'vhost' => '/',
                ],
                'conn2' => [
                    'host' => 'rabbitmqhostasdsa',
                    'port' => 123,
                    'user' => 'foo_user',
                    'pass' => 'foo_pass',
                    'vhost' => '/foo',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert that both connections are created properly
        foreach (range(1, 2) as $num) {
            $key = sprintf('conn%d', $num);
            $serviceId = sprintf('tree_house.queue.connection.%s', $key);

            // assert the service is created
            $this->assertContainerBuilderHasService(
                $serviceId,
                $this->container->getParameter('tree_house.queue.driver.amqp.connection.class')
            );

            // assert the constructor args
            $this->assertContainerBuilderHasServiceDefinitionWithArgument($serviceId, 0, $config['connections'][$key]['host']);
            $this->assertContainerBuilderHasServiceDefinitionWithArgument($serviceId, 1, $config['connections'][$key]['port']);
            $this->assertContainerBuilderHasServiceDefinitionWithArgument($serviceId, 2, $config['connections'][$key]['user']);
            $this->assertContainerBuilderHasServiceDefinitionWithArgument($serviceId, 3, $config['connections'][$key]['pass']);
            $this->assertContainerBuilderHasServiceDefinitionWithArgument($serviceId, 4, $config['connections'][$key]['vhost']);

            // assert that a channel is also created
            $channelServiceId = sprintf('tree_house.queue.channel.%s', $key);
            $this->assertContainerBuilderHasService(
                $channelServiceId,
                $this->container->getParameter('tree_house.queue.driver.amqp.channel.class')
            );

            // assert that the channel receives this connection
            $this->assertContainerBuilderHasServiceDefinitionWithArgument(
                $channelServiceId,
                0,
                new Reference($serviceId)
            );
        }

        // assert that the first connection is the default
        $this->assertContainerBuilderHasAlias(
            'tree_house.queue.default_connection',
            'tree_house.queue.connection.conn1'
        );

        // assert that the created connections are stored in a parameter
        $this->assertContainerBuilderHasParameter(
            'tree_house.queue.connections',
            [
                'conn1' => 'tree_house.queue.connection.conn1',
                'conn2' => 'tree_house.queue.connection.conn2',
            ]
        );
    }

    /**
     * @test
     */
    public function the_default_connection_can_be_set()
    {
        $config = [
            'default_connection' => 'conn2',
            'connections' => [
                'conn1' => [
                    'host' => 'localhost',
                    'port' => 5672,
                    'user' => 'guest',
                    'pass' => 'guest',
                    'vhost' => '/',
                ],
                'conn2' => [
                    'host' => 'rabbitmqhost',
                    'port' => 123,
                    'user' => 'foo_user',
                    'pass' => 'foo_pass',
                    'vhost' => '/foo',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderHasAlias(
            'tree_house.queue.default_connection',
            'tree_house.queue.connection.conn2'
        );
    }

    /**
     * @test
     */
    public function it_throws_an_exception_on_missing_default_connection()
    {
        $config = [
            'default_connection' => 'foo',
            'connections' => [
                'conn1' => [
                    'host' => 'localhost',
                    'port' => 5672,
                    'user' => 'guest',
                    'pass' => 'guest',
                    'vhost' => '/',
                ],
            ],
        ];

        $this->expectExceptionMessage('Connection "tree_house.queue.connection.foo" does not exist');
        $this->expectException(LogicException::class);

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));
    }

    /**
     * @test
     */
    public function it_should_create_publisher_definitions_with_defaults()
    {
        $config = [
            'publishers' => [
                'process1' => [],
                'process2' => [],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert channel
        $channelId = 'tree_house.queue.channel.process2';
        $this->assertContainerBuilderHasAlias($channelId, 'tree_house.queue.channel.conn1');

        // assert exchange and its arguments
        $exchangeId = 'tree_house.queue.exchange.process2';
        $this->assertContainerBuilderHasService($exchangeId, $this->container->getParameter('tree_house.queue.exchange.class'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 0, new Reference($channelId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 1, 'process2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 2, ExchangeInterface::TYPE_DELAYED);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 3, ExchangeInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 4, ['x-delayed-type' => ExchangeInterface::TYPE_DIRECT]);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($exchangeId, 'declareExchange');

        // assert dead letter exchange and its arguments
        $dlxId = 'tree_house.queue.exchange.process2.dead';
        $dlxChannelId = sprintf('%s.dead', $channelId);
        $this->assertContainerBuilderHasService($dlxId, $this->container->getParameter('tree_house.queue.exchange.class'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 0, new Reference($dlxChannelId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 1, 'process2.dead');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 2, ExchangeInterface::TYPE_DIRECT);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 3, ExchangeInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 4, []);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($dlxId, 'declareExchange');

        // assert that a message composer and serializer have been created with an alias
        $serializerId = 'tree_house.queue.serializer.process2';
        $composerId = 'tree_house.queue.composer.process2';
        $this->assertContainerBuilderHasAlias($serializerId, 'tree_house.queue.serializer.php');
        $this->assertContainerBuilderHasService($composerId, $this->container->getParameter('tree_house.queue.composer.default.class'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($composerId, 0, new Reference($serializerId));

        // assert that a publisher has been created
        $publisherId = 'tree_house.queue.publisher.process2';
        $this->assertContainerBuilderHasService($publisherId, $this->container->getParameter('tree_house.queue.publisher.class'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($publisherId, 0, new Reference($exchangeId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($publisherId, 1, new Reference($composerId));

        // assert that the created publishers are stored in a parameter
        $this->assertContainerBuilderHasParameter(
            'tree_house.queue.publishers',
            [
                'process1' => 'tree_house.queue.publisher.process1',
                'process2' => 'tree_house.queue.publisher.process2',
            ]
        );
    }

    /**
     * @test
     */
    public function it_should_create_publisher_with_different_serializer()
    {
        $config = [
            'publishers' => [
                'process2' => [
                    'serializer' => 'json',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $serializerId = 'tree_house.queue.serializer.process2';
        $this->assertContainerBuilderHasAlias($serializerId, 'tree_house.queue.serializer.json');
    }

    /**
     * @test
     */
    public function it_should_create_publisher_with_custom_serializer()
    {
        $config = [
            'publishers' => [
                'process2' => [
                    'serializer' => 'My\\Serializer',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderHasService('tree_house.queue.serializer.process2', 'My\\Serializer');
    }

    /**
     * @test
     */
    public function it_should_create_publisher_with_serializer_service()
    {
        $this->registerService('my_serializer', 'My\\Serializer');
        $config = [
            'publishers' => [
                'process2' => [
                    'serializer' => '@my_serializer',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderHasAlias('tree_house.queue.serializer.process2', 'my_serializer');
    }

    /**
     * @test
     */
    public function it_should_create_publisher_with_composer_service()
    {
        $this->registerService('my_composer', 'My\\Composer');
        $config = [
            'publishers' => [
                'process2' => [
                    'composer' => '@my_composer',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderHasAlias('tree_house.queue.composer.process2', 'my_composer');
    }

    /**
     * @test
     */
    public function it_should_create_publisher_with_custom_composer()
    {
        $this->setParameter('my.composer.class', 'My\\Composer');
        $config = [
            'publishers' => [
                'process2' => [
                    'composer' => '%my.composer.class%',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $this->assertContainerBuilderHasService('tree_house.queue.composer.process2', 'My\\Composer');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            'tree_house.queue.composer.process2',
            0,
            new Reference('tree_house.queue.serializer.process2')
        );
    }

    /**
     * @test
     */
    public function it_should_create_publisher_with_custom_exchange()
    {
        $this->setParameter('my.composer.class', 'My\\Composer');
        $config = [
            'publishers' => [
                'process2' => [
                    'exchange' => [
                        'name' => 'foo',
                        'type' => ExchangeInterface::TYPE_FANOUT,
                        'connection' => 'conn2',
                        'passive' => true,
                        'arguments' => [
                          'x-ha-policy' => 'all',
                        ],
                        'delay' => false,
                        'dlx' => [
                            'name' => 'dead_foos',
                            'type' => ExchangeInterface::TYPE_TOPIC,
                        ]
                    ],
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $exchangeId = 'tree_house.queue.exchange.process2';
        $this->assertContainerBuilderHasAlias('tree_house.queue.channel.process2', 'tree_house.queue.channel.conn2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 1, 'foo');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 2, ExchangeInterface::TYPE_FANOUT);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 3, ExchangeInterface::PASSIVE | ExchangeInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 4, ['x-ha-policy' => 'all']);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($exchangeId, 'declareExchange');

        // assert dead letter exchange and its arguments
        $dlxId = 'tree_house.queue.exchange.dead_foos';
        $dlxChannelId = 'tree_house.queue.channel.dead_foos';
        $this->assertContainerBuilderHasService($dlxId, $this->container->getParameter('tree_house.queue.exchange.class'));
        $this->assertContainerBuilderHasAlias($dlxChannelId, 'tree_house.queue.channel.conn2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 0, new Reference($dlxChannelId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 1, 'dead_foos');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 2, ExchangeInterface::TYPE_TOPIC);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($dlxId, 'declareExchange');
    }

    /**
     * @test
     */
    public function it_should_not_create_delayed_exchange_for_publisher()
    {
        $config = [
            'publishers' => [
                'process2' => [
                    'exchange' => [
                        'delay' => false
                    ]
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert channel
        $channelId = 'tree_house.queue.channel.process2';
        $this->assertContainerBuilderHasAlias($channelId, 'tree_house.queue.channel.conn1');

        // assert exchange and its arguments
        $exchangeId = 'tree_house.queue.exchange.process2';
        $this->assertContainerBuilderHasService($exchangeId, $this->container->getParameter('tree_house.queue.exchange.class'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 0, new Reference($channelId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 1, 'process2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 2, ExchangeInterface::TYPE_DIRECT);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 3, ExchangeInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 4, []);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($exchangeId, 'declareExchange');
    }

    /**
     * @test
     */
    public function it_should_create_consumer_definitions_with_defaults()
    {
        $config = [
            'consumers' => [
                'process1' => [
                    'processor' => 'My\\Processor',
                ],
                'process2' => [
                    'processor' => 'My\\Processor',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert queue and its arguments
        $queueId = 'tree_house.queue.queue.process2';
        $this->assertContainerBuilderHasService($queueId, $this->container->getParameter('tree_house.queue.queue.class'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 0, new Reference('tree_house.queue.channel.conn1'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 1, 'process2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 2, QueueInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 3, []);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'declareQueue');
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['process2', null, []]);

        // assert processor
        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasService($processorId, 'My\\Processor');

        // assert that a consumer has been created
        $consumerId = 'tree_house.queue.consumer.process2';
        $this->assertContainerBuilderHasService($consumerId);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($consumerId, 0, new Reference($queueId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($consumerId, 1, new Reference($processorId));

        // assert that the created consumers are stored in a parameter
        $this->assertContainerBuilderHasParameter(
            'tree_house.queue.consumers',
            [
                'process1' => 'tree_house.queue.consumer.process1',
                'process2' => 'tree_house.queue.consumer.process2',
            ]
        );
    }

    /**
     * @test
     */
    public function it_should_connect_consumer_queue_to_existing_dlx()
    {
        $config = [
            'publishers' => [
                'process1' => [],
            ],
            'consumers' => [
                'process1' => [
                    'processor' => 'My\\Processor',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert queue and its arguments
        $queueId = 'tree_house.queue.queue.process1';
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 3, ['x-dead-letter-exchange' => 'process1.dead']);
    }

    /**
     * @test
     */
    public function consumer_creates_queue_with_custom_values()
    {
        $config = [
            'connections' => [
                'conn1' => ['host' => 'localhost'],
                'conn2' => ['host' => 'rabbitmqhost'],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => 'My\\Processor',
                    'queue' => [
                        'name' => 'foo',
                        'connection' => 'conn2',
                        'durable' => false,
                        'passive' => true,
                        'exclusive' => true,
                        'auto_delete' => true,
                        'arguments' => [
                            'x-match' => 'all',
                        ],
                        'binding' => [
                            'exchange' => 'xchg1',
                            'routing_key' => 'foo',
                            'arguments' => [
                                'x-foo' => 'bar',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert queue and its arguments
        $queueId = 'tree_house.queue.queue.process2';
        $this->assertContainerBuilderHasService($queueId);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 0, new Reference('tree_house.queue.channel.conn2'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 1, 'foo');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 2, QueueInterface::PASSIVE | QueueInterface::EXCLUSIVE | QueueInterface::AUTODELETE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 3, ['x-match' => 'all']);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'declareQueue');

        // assert bindings
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['xchg1', 'foo', ['x-foo' => 'bar']]);
    }

    /**
     * @test
     */
    public function consumer_can_use_processor_alias()
    {
        $this->registerService('my_processor', 'My\\Processor');
        $config = [
            'consumers' => [
                'process2' => [
                    'processor' => '@my_processor',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasAlias($processorId, 'my_processor');
    }

    /**
     * @test
     */
    public function consumer_can_decorate_retry_processor()
    {
        $config = [
            'publishers' => [
                'process2' => [],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => 'My\\Processor',
                    'retry' => 2,
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasService($processorId, RetryProcessor::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 0, (new Definition('My\\Processor'))->setPublic(false));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 2, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($processorId, 'setMaxAttempts', [2]);
    }

    /**
     * @test
     */
    public function consumer_can_decorate_retry_processor_service()
    {
        $this->registerService('my_processor', 'My\\Processor');
        $config = [
            'publishers' => [
                'process2' => [],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => '@my_processor',
                    'retry' => 2,
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasService($processorId, RetryProcessor::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 0, new Reference('my_processor'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 2, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($processorId, 'setMaxAttempts', [2]);
    }

    /**
     * @test
     */
    public function consumer_can_specify_retry_publisher()
    {
        $config = [
            'publishers' => [
                'foo' => [],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => 'My\\Processor',
                    'retry' => [
                        'attempts' => 2,
                        'publisher' => 'foo'
                    ],
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $strategy = new Definition(BackoffStrategy::class);
        $strategy->addArgument(new Reference('tree_house.queue.publisher.foo'));
        $strategy->setPublic(false);

        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 1, $strategy);
    }

    /**
     * @test
     */
    public function consumer_can_create_retry_processor_with_strategy()
    {
        $config = [
            'publishers' => [
                'process2' => [],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => 'My\\Processor',
                    'retry' => [
                        'attempts' => 2,
                        'strategy' => 'deprioritize'
                    ],
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $strategy = new Definition(DeprioritizeStrategy::class);
        $strategy->addArgument(new Reference('tree_house.queue.publisher.process2'));
        $strategy->setPublic(false);

        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 1, $strategy);
    }

    /**
     * @test
     */
    public function consumer_can_create_retry_processor_with_extended_strategy_config()
    {
        $config = [
            'publishers' => [
                'process2' => [],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => 'My\\Processor',
                    'retry' => [
                        'attempts' => 2,
                        'strategy' => [
                            'type' => 'deprioritize'
                        ],
                    ],
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $strategy = new Definition(DeprioritizeStrategy::class);
        $strategy->addArgument(new Reference('tree_house.queue.publisher.process2'));
        $strategy->setPublic(false);

        $processorId = 'tree_house.queue.processor.process2';
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($processorId, 1, $strategy);
    }

    /**
     * @test
     */
    public function it_throws_an_exception_on_unsupported_strategy()
    {
        $config = [
            'publishers' => [
                'process2' => [],
            ],
            'consumers' => [
                'process2' => [
                    'processor' => 'My\\Processor',
                    'retry' => [
                        'attempts' => 2,
                        'strategy' => 'missing',
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));
    }

    /**
     * @test
     */
    public function it_should_create_exchange_definitions()
    {
        $config = [
            'exchanges' => [
                'foo' => [
                    'dlx' => false
                ],
                'bar' => [
                    'name' => 'foobar',
                    'type' => ExchangeInterface::TYPE_FANOUT,
                    'connection' => 'conn2',
                    'passive' => true,
                    'arguments' => [
                        'x-ha-policy' => 'all',
                    ],
                    'dlx' => [
                        'name' => 'dead_bars',
                        'type' => ExchangeInterface::TYPE_TOPIC,
                    ]
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert first exchange (defaults)
        $exchangeId = 'tree_house.queue.exchange.foo';
        $this->assertContainerBuilderHasAlias('tree_house.queue.channel.foo', 'tree_house.queue.channel.conn1');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 1, 'foo');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 2, ExchangeInterface::TYPE_DELAYED);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 3, ExchangeInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 4, ['x-delayed-type' => ExchangeInterface::TYPE_DIRECT]);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($exchangeId, 'declareExchange');

        // assert first exchange has no DLX
        $dlxId = 'tree_house.queue.exchange.foo.dead';
        $this->assertContainerBuilderNotHasService($dlxId);

        // assert second exchange and its arguments
        $exchangeId = 'tree_house.queue.exchange.foobar';
        $this->assertContainerBuilderHasAlias('tree_house.queue.channel.foobar', 'tree_house.queue.channel.conn2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 1, 'foobar');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 2, ExchangeInterface::TYPE_DELAYED);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 3, ExchangeInterface::DURABLE | ExchangeInterface::PASSIVE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($exchangeId, 4, ['x-delayed-type' => ExchangeInterface::TYPE_FANOUT, 'x-ha-policy' => 'all']);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($exchangeId, 'declareExchange');

        // assert DLX for second exchange
        $dlxId = 'tree_house.queue.exchange.dead_bars';
        $dlxChannelId = 'tree_house.queue.channel.dead_bars';
        $this->assertContainerBuilderHasService($dlxId, $this->container->getParameter('tree_house.queue.exchange.class'));
        $this->assertContainerBuilderHasAlias($dlxChannelId, 'tree_house.queue.channel.conn2');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 0, new Reference($dlxChannelId));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 1, 'dead_bars');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 2, ExchangeInterface::TYPE_TOPIC);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 3, ExchangeInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($dlxId, 4, []);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($dlxId, 'declareExchange');

        // assert queue for DLX
        $queueId = 'tree_house.queue.queue.dead_bars';
        $this->assertContainerBuilderHasService($queueId);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 0, new Reference('tree_house.queue.channel.conn2'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 1, 'dead_bars');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 2, QueueInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 3, []);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'declareQueue');
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['dead_bars', null, []]);

        // assert that the created exchanges are stored in a parameter
        $this->assertContainerBuilderHasParameter(
            'tree_house.queue.exchanges',
            [
                'foo' => [
                    'id' => 'tree_house.queue.exchange.foo',
                    'auto_declare' => true,
                ],
                'foobar' => [
                    'id' => 'tree_house.queue.exchange.foobar',
                    'auto_declare' => true,
                ],
                'dead_bars' => [
                    'id' => 'tree_house.queue.exchange.dead_bars',
                    'auto_declare' => true,
                ],
            ]
        );
    }

    /**
     * @test
     */
    public function it_should_not_auto_declare_exchange()
    {
        $config = [
            'exchanges' => [
                'foo' => [
                    'auto_declare' => false,
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $exchangeId = 'tree_house.queue.exchange.foo';
        $definition = $this->container->findDefinition($exchangeId);
        $this->assertThat($definition, $this->logicalNot(new DefinitionHasMethodCallConstraint('declareExchange')));
    }

    /**
     * @test
     */
    public function it_should_create_queue_definitions()
    {
        $config = [
            'queues' => [
                'foo' => [
                    'name' => 'foo',
                    'connection' => 'conn1',
                    'durable' => true,
                    'arguments' => [
                        'x-match' => 'all',
                    ],
                    'binding' => [
                        'exchange' => 'xchg1',
                    ],
                ],
                'bar' => [
                    'name' => 'bar',
                    'connection' => 'conn2',
                    'durable' => false,
                    'passive' => true,
                    'exclusive' => true,
                    'auto_delete' => true,
                    'auto_declare' => false,
                    'bindings' => [
                        [
                            'exchange' => 'xchg1',
                            'routing_keys' => [
                                'foo',
                                'bar',
                            ],
                            'arguments' => [
                                'x-foo' => 'bar',
                            ],
                        ],
                        [
                            'exchange' => 'xchg2',
                            'routing_key' => 'foo',
                        ],
                    ],
                    'dlx' => 'dead_letter_exchange_name',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        // assert queue 1
        $queueId = 'tree_house.queue.queue.foo';
        $this->assertContainerBuilderHasService($queueId);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 0, new Reference('tree_house.queue.channel.conn1'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 1, 'foo');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 2, QueueInterface::DURABLE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 3, ['x-match' => 'all']);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'declareQueue');
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['xchg1', null, []]);

        // assert queue 2
        $queueId = 'tree_house.queue.queue.bar';
        $this->assertContainerBuilderHasService($queueId);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 0, new Reference('tree_house.queue.channel.conn2'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 1, 'bar');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 2, QueueInterface::PASSIVE | QueueInterface::EXCLUSIVE | QueueInterface::AUTODELETE);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument($queueId, 3, ['x-dead-letter-exchange' => 'dead_letter_exchange_name']);
        $this->assertThat($this->container->findDefinition($queueId), $this->logicalNot(new DefinitionHasMethodCallConstraint('declareQueue')));
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['xchg1', 'foo', ['x-foo' => 'bar']]);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['xchg1', 'bar', ['x-foo' => 'bar']]);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['xchg2', 'foo', []]);

        // assert that the created queues are stored in a parameter
        $this->assertContainerBuilderHasParameter(
            'tree_house.queue.queues',
            [
                'foo' => [
                    'id' => 'tree_house.queue.queue.foo',
                    'auto_declare' => true,
                ],
                'bar' => [
                    'id' => 'tree_house.queue.queue.bar',
                    'auto_declare' => false,
                ],
            ]
        );
    }

    /**
     * @test
     */
    public function it_should_create_queue_with_a_default_binding()
    {
        $config = [
            'queues' => [
                'foo' => [
                    'name' => 'foo',
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $queueId = 'tree_house.queue.queue.foo';
        $this->assertContainerBuilderHasService($queueId);
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall($queueId, 'bind', ['foo', null, []]);
    }

    /**
     * @test
     */
    public function it_should_not_auto_declare_queue()
    {
        $config = [
            'queues' => [
                'foo' => [
                    'auto_declare' => false,
                ],
            ],
        ];

        $this->load($config = array_merge(self::CONNECTIONS_CONFIG, $config));

        $queueId = 'tree_house.queue.queue.foo';
        $definition = $this->container->findDefinition($queueId);
        $this->assertThat($definition, $this->logicalNot(new DefinitionHasMethodCallConstraint('declareQueue')));
    }

    /**
     * @inheritdoc
     */
    protected function getContainerExtensions(): array
    {
        return [new TreeHouseQueueExtension()];
    }
}
