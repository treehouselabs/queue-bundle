<?php

namespace TreeHouse\QueueBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use TreeHouse\Queue\Amqp\ExchangeInterface;
use TreeHouse\Queue\Amqp\QueueInterface;
use TreeHouse\Queue\Processor\Retry\BackoffStrategy;
use TreeHouse\Queue\Processor\Retry\DeprioritizeStrategy;
use TreeHouse\Queue\Processor\Retry\RetryProcessor;

class TreeHouseQueueExtension extends Extension
{
    /**
     * @var string[]
     */
    private $connections = [];

    /**
     * @var string[]
     */
    private $exchanges = [];

    /**
     * @var string[]
     */
    private $queues = [];

    /**
     * @var string[]
     */
    private $publishers = [];

    /**
     * @var string[]
     */
    private $consumers = [];

    /**
     * @var array Map that links exchanges with a DLX counterpart
     */
    private $dlxs = [];

    /**
     * @inheritdoc
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        $this->loadDriver($config, $container);
        $this->loadConnections($config, $container);
        $this->loadPublishers($config, $container);
        $this->loadConsumers($config, $container);
        $this->loadExchanges($config, $container);
        $this->loadQueues($config, $container);

        $this->setCreatedDefinitionsParameters($container);

        if (!$config['auto_flush']) {
            $container->removeDefinition('tree_house.queue.event_listener.queue');
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function loadDriver(array $config, ContainerBuilder $container)
    {
        $container->setParameter('tree_house.queue.driver', $config['driver']);
        $container->setAlias(
            'tree_house.amqp.factory',
            sprintf('tree_house.queue.driver.%s.factory', $config['driver'])
        );

        $classes = ['connection', 'channel', 'exchange', 'queue', 'publisher', 'factory'];
        foreach ($classes as $class) {
            $name = sprintf('tree_house.queue.%s.class', $class);
            if (!$container->hasParameter($name)) {
                $value = sprintf('tree_house.queue.driver.%s.%s.class', $config['driver'], $class);
                $container->setParameter($name, $container->getParameter($value));
            }
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @throws \LogicException
     */
    private function loadConnections(array $config, ContainerBuilder $container)
    {
        foreach ($config['connections'] as $name => $connection) {
            $this->createConnectionDefinition($name, $connection, $container);
        }

        // default the first connection if it wasn't explicitly set
        if (!$config['default_connection']) {
            reset($config['connections']);
            $config['default_connection'] = key($config['connections']);
        }

        // set a parameter and alias for the default connection
        $connectionId = sprintf('tree_house.queue.connection.%s', $config['default_connection']);
        if (!$container->hasDefinition($connectionId)) {
            throw new \LogicException(sprintf('Connection "%s" does not exist', $connectionId));
        }

        $container->setParameter('tree_house.queue.default_connection', $config['default_connection']);
        $container->setAlias('tree_house.queue.default_connection', $connectionId);
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function loadPublishers(array $config, ContainerBuilder $container)
    {
        foreach ($config['publishers'] as $name => $publisher) {
            $this->createPublisherDefinition($name, $publisher, $container);
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function loadConsumers(array $config, ContainerBuilder $container)
    {
        foreach ($config['consumers'] as $name => $consumer) {
            $this->createConsumerDefinition($name, $consumer, $container);
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function loadExchanges(array $config, ContainerBuilder $container)
    {
        foreach ($config['exchanges'] as $name => $exchange) {
            $this->createExchangeDefinition($name, $exchange, $container);
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function loadQueues(array $config, ContainerBuilder $container)
    {
        foreach ($config['queues'] as $name => $queue) {
            $this->createQueueDefinition($name, $queue, $container);
        }
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createConnectionDefinition($name, array $config, ContainerBuilder $container)
    {
        $amqpFactory = new Reference('tree_house.amqp.factory');

        $definition = new Definition($container->getParameter('tree_house.queue.connection.class'));
        $definition->setFactory([$amqpFactory, 'createConnection']);
        $definition->addArgument($config['host']);
        $definition->addArgument((integer) $config['port']);
        $definition->addArgument($config['user']);
        $definition->addArgument($config['pass']);
        $definition->addArgument($config['vhost']);
        $definition->addArgument($config['params']);

        $connectionId = sprintf('tree_house.queue.connection.%s', $name);
        $container->setDefinition($connectionId, $definition);

        // create channel
        $definition = new Definition($container->getParameter('tree_house.queue.channel.class'));
        $definition->setFactory([$amqpFactory, 'createChannel']);
        $definition->addArgument(new Reference($connectionId));

        // TODO set qos and prefetch stuff

        $channelId = sprintf('tree_house.queue.channel.%s', $name);
        $container->setDefinition($channelId, $definition);

        $this->connections[$name] = $connectionId;

        return $connectionId;
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createExchangeDefinition($name, array $config, ContainerBuilder $container)
    {
        $amqpFactory = new Reference('tree_house.amqp.factory');

        $connection = $config['connection'] ?: $container->getParameter('tree_house.queue.default_connection');
        $channelId = sprintf('tree_house.queue.channel.%s', $connection);
        $channelAlias = sprintf('tree_house.queue.channel.%s', $name);

        // add alias if connection is named differently than exchange
        if ($name !== $connection) {
            $container->setAlias($channelAlias, $channelId);
        }

        $exchangeName = $config['name'] ?: $name;
        $exchangeType = $config['type'];
        $exchangeFlags = $this->getExchangeFlagsValue($config);
        $exchangeArguments = $config['arguments'];
        $autoDeclare = isset($config['auto_declare']) ? $config['auto_declare'] : true;

        // optionally create a delayed message exchange counterpart
        if (isset($config['delay']) && $config['delay']) {
            $exchangeArguments['x-delayed-type'] = $exchangeType;
            $exchangeType = ExchangeInterface::TYPE_DELAYED;
        }

        // create exchange
        $definition = new Definition($container->getParameter('tree_house.queue.exchange.class'));
        $definition->setFactory([$amqpFactory, 'createExchange']);
        $definition->addArgument(new Reference($channelAlias));
        $definition->addArgument($exchangeName);
        $definition->addArgument($exchangeType);
        $definition->addArgument($exchangeFlags);
        $definition->addArgument($exchangeArguments);

        if ($autoDeclare) {
            $definition->addMethodCall('declareExchange');
        }

        $exchangeId = sprintf('tree_house.queue.exchange.%s', $name);
        $container->setDefinition($exchangeId, $definition);

        $this->exchanges[$name] = [
            'id' => $exchangeId,
            'auto_declare' => $autoDeclare,
        ];

        // optionally create a dead letter exchange counterpart
        if (isset($config['dlx']['enabled']) && $config['dlx']['enabled']) {
            if (!isset($config['dlx']['name'])) {
                $config['dlx']['name'] = sprintf('%s.dead', $exchangeName);
            }

            if (!isset($config['dlx']['connection'])) {
                $config['dlx']['connection'] = $connection;
            }

            if (!isset($config['dlx']['auto_declare'])) {
                $config['dlx']['auto_declare'] = $autoDeclare;
            }

            $dlxName = $config['dlx']['name'];
            $dlxId = $this->createExchangeDefinition($dlxName, $config['dlx'], $container);

            $this->dlxs[$name] = $dlxId;

            // create queue to route this DLX to
            $queue = $config['dlx']['queue'];
            if (!isset($queue['name'])) {
                $queue['name'] = $dlxName;
            }

            if (!isset($queue['connection'])) {
                $queue['connection'] = $connection;
            }

            $hasBinding = false;
            foreach ($queue['bindings'] as $binding) {
                if ($binding['exchange'] === $dlxName) {
                    $hasBinding = true;
                    break;
                }
            }
            if (!$hasBinding) {
                $queue['bindings'][] = [
                    'exchange' => $dlxName,
                    'arguments' => [],
                ];
            }

            $this->createQueueDefinition($dlxName, $queue, $container);
        }

        return $exchangeId;
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     * @param string           $name
     *
     * @return string
     */
    private function createConsumerDefinition($name, array $config, ContainerBuilder $container)
    {
        // create the queue
        $queue = $config['queue'];

        if (!isset($queue['name'])) {
            $queue['name'] = $name;
        }

        $queueId = $this->createQueueDefinition($name, $queue, $container);

        // create the processor
        $processorId = $this->createProcessorDefinition($name, $config, $container);

        // create the consumer
        $definition = new ChildDefinition('tree_house.queue.consumer.prototype');
        $definition->addArgument(new Reference($queueId));
        $definition->addArgument(new Reference($processorId));
        $definition->addArgument(new Reference('event_dispatcher'));

        $consumerId = sprintf('tree_house.queue.consumer.%s', $name);
        $container->setDefinition($consumerId, $definition);

        $this->consumers[$name] = $consumerId;

        return $consumerId;
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createQueueDefinition($name, array $config, ContainerBuilder $container)
    {
        $amqpFactory = new Reference('tree_house.amqp.factory');

        $connection = $config['connection'] ?: $container->getParameter('tree_house.queue.default_connection');
        $queueName = $config['name'] ?: $name;
        $channelId = sprintf('tree_house.queue.channel.%s', $connection);
        $arguments = $config['arguments'];
        $autoDeclare = isset($config['auto_declare']) ? $config['auto_declare'] : true;

        // if there is an exchange with the same name, and it has a DLX configured, set this in the arguments
        if (!array_key_exists('x-dead-letter-exchange', $arguments) && $dlx = $this->getDeadLetterExchange($name, $config, $container)) {
            $arguments['x-dead-letter-exchange'] = $dlx;
        }

        // create queue
        $definition = new Definition($container->getParameter('tree_house.queue.queue.class'));
        $definition->setFactory([$amqpFactory, 'createQueue']);
        $definition->addArgument(new Reference($channelId));
        $definition->addArgument($queueName);
        $definition->addArgument($this->getQueueFlagsValue($config));
        $definition->addArgument($arguments);

        if ($autoDeclare) {
            $definition->addMethodCall('declareQueue');
        }

        if (empty($config['bindings'])) {
            // bind to the same exchange
            $config['bindings'][] = [
                'exchange' => $name,
                'routing_keys' => [],
                'arguments' => [],
            ];
        }

        foreach ($config['bindings'] as $binding) {
            // if nothing is set, bind without routing key
            if (empty($binding['routing_keys'])) {
                $binding['routing_keys'] = [null];
            }

            foreach ($binding['routing_keys'] as $routingKey) {
                $definition->addMethodCall('bind', [$binding['exchange'], $routingKey, $binding['arguments']]);
            }
        }

        $queueId = sprintf('tree_house.queue.queue.%s', $name);
        $container->setDefinition($queueId, $definition);

        $this->queues[$name] = [
            'id' => $queueId,
            'auto_declare' => $autoDeclare,
        ];

        return $queueId;
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createPublisherDefinition($name, array $config, ContainerBuilder $container)
    {
        // get the right channel for the exchange
        $exchange = $config['exchange'];
        $exchangeId = $this->createExchangeDefinition($name, $exchange, $container);

        // create message composer
        $composerId = $this->createMessageComposerDefinition($name, $config, $container);

        // create publisher
        $publisherId = sprintf('tree_house.queue.publisher.%s', $name);
        $publisher = new Definition($container->getParameter('tree_house.queue.publisher.class'));
        $publisher->setLazy(true);
        $publisher->addArgument(new Reference($exchangeId));
        $publisher->addArgument(new Reference($composerId));

        $container->setDefinition($publisherId, $publisher);

        $this->publishers[$name] = $publisherId;

        return $publisherId;
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createMessageComposerDefinition($name, array $config, ContainerBuilder $container)
    {
        $composerId = sprintf('tree_house.queue.composer.%s', $name);
        $composer = $config['composer'];

        // resolve service
        if (substr($composer, 0, 1) === '@') {
            $container->setAlias($composerId, ltrim($composer, '@'));
        } else {
            // resolve parameter
            if (substr($composer, 0, 1) === '%') {
                $composer = $container->getParameter(substr($composer, 1, -1));
            }

            // create serializer first
            $serializerId = $this->createMessageSerializerDefinition($name, $config['serializer'], $container);

            $composerDef = new Definition($composer);
            $composerDef->addArgument(new Reference($serializerId));
            $container->setDefinition($composerId, $composerDef);
        }

        return $composerId;
    }

    /**
     * @param string           $name
     * @param string           $serializerClass
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createMessageSerializerDefinition($name, $serializerClass, ContainerBuilder $container)
    {
        $serializerId = sprintf('tree_house.queue.serializer.%s', $name);

        // resolve service
        if (substr($serializerClass, 0, 1) === '@') {
            $container->setAlias($serializerId, ltrim($serializerClass, '@'));

            return $serializerId;
        } else {
            $serializer = new Definition($serializerClass);
            $container->setDefinition($serializerId, $serializer);

            return $serializerId;
        }
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createProcessorDefinition($name, array $config, ContainerBuilder $container)
    {
        $processorId = sprintf('tree_house.queue.processor.%s', $name);

        if (substr($config['processor'], 0, 1) === '@') {
            $service = ltrim($config['processor'], '@');
        } else {
            $service = new Definition($config['processor']);
            $service->setPublic(false);
        }

        // decorate the process with a retry processor if needd
        $service = $this->decorateRetryProcessor($name, $config['retry'], $service, $container);

        if (is_string($service)) {
            $container->setAlias($processorId, $service);
        } else {
            $container->setDefinition($processorId, $service);
        }

        return $processorId;
    }

    /**
     * @param string            $name
     * @param array             $config
     * @param string|Definition $service
     * @param ContainerBuilder  $container
     *
     * @return Definition
     */
    private function decorateRetryProcessor($name, array $config, $service, ContainerBuilder $container)
    {
        // skip if we only use 1 attempt
        if ($config['attempts'] < 2) {
            return $service;
        }

        $publisherName = $config['publisher'] ?: $name;
        $publisherId = sprintf('tree_house.queue.publisher.%s', $publisherName);

        if (!$container->hasDefinition($publisherId)) {
            throw new InvalidConfigurationException(sprintf('There is no publisher named "%s" configured.', $publisherName));
        }

        // decorate the processor
        $strategy = $this->createRetryStrategyDefinition($config['strategy'], $publisherId);

        $retry = new Definition(RetryProcessor::class);
        $retry->addArgument(is_string($service) ? new Reference($service) : $service);
        $retry->addArgument(is_string($strategy) ? new Reference($strategy) : $strategy);
        $retry->addArgument(new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $retry->addMethodCall('setMaxAttempts', [$config['attempts']]);

        return $retry;
    }

    /**
     * @param array  $config
     * @param string $publisherId
     *
     * @return Definition
     */
    private function createRetryStrategyDefinition(array $config, $publisherId)
    {
        switch ($config['type']) {
            case 'backoff':
                $strategy = new Definition(BackoffStrategy::class);
                $strategy->addArgument(new Reference($publisherId));

                break;
            case 'deprioritize':
                $strategy = new Definition(DeprioritizeStrategy::class);
                $strategy->addArgument(new Reference($publisherId));

                break;
            default:
                throw new InvalidConfigurationException(sprintf('Unsupported retry strategy: "%s"', $config['type']));
        }

        $strategy->setPublic(false);

        return $strategy;
    }

    /**
     * @param ContainerBuilder $container
     */
    private function setCreatedDefinitionsParameters(ContainerBuilder $container)
    {
        $container->setParameter('tree_house.queue.connections', $this->connections);
        $container->setParameter('tree_house.queue.exchanges', $this->exchanges);
        $container->setParameter('tree_house.queue.queues', $this->queues);
        $container->setParameter('tree_house.queue.publishers', $this->publishers);
        $container->setParameter('tree_house.queue.consumers', $this->consumers);
    }

    /**
     * @param string           $name
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return null|string
     */
    private function getDeadLetterExchange($name, array $config, ContainerBuilder $container)
    {
        if (isset($config['dlx'])) {
            return $config['dlx'];
        }

        if (!isset($this->dlxs[$name])) {
            return null;
        }

        $dlx = $container->getDefinition($this->dlxs[$name]);

        return $dlx->getArgument(1);
    }

    /**
     * @param array $exchange
     *
     * @return int
     */
    private function getExchangeFlagsValue(array $exchange)
    {
        $flags = ExchangeInterface::NOPARAM;

        if ($exchange['durable']) {
            $flags |= ExchangeInterface::DURABLE;
        }

        if ($exchange['passive']) {
            $flags |= ExchangeInterface::PASSIVE;
        }

        return $flags;
    }

    /**
     * @param array $queue
     *
     * @return int
     */
    private function getQueueFlagsValue(array $queue)
    {
        $flags = QueueInterface::NOPARAM;

        if ($queue['durable']) {
            $flags |= QueueInterface::DURABLE;
        }

        if ($queue['passive']) {
            $flags |= QueueInterface::PASSIVE;
        }

        if ($queue['exclusive']) {
            $flags |= QueueInterface::EXCLUSIVE;
        }

        if ($queue['auto_delete']) {
            $flags |= QueueInterface::AUTODELETE;
        }

        return $flags;
    }
}
