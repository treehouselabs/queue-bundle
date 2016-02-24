<?php

namespace TreeHouse\QueueBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
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

        $classes = ['connection', 'channel', 'exchange', 'queue', 'provider', 'publisher', 'factory'];
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
        $exchangeName = $config['name'] ?: $name;

        // add alias if connection is named differently than exchange
        if ($name !== $connection) {
            $container->setAlias($channelAlias, $channelId);
        }

        // create exchange
        $definition = new Definition($container->getParameter('tree_house.queue.exchange.class'));
        $definition->setFactory([$amqpFactory, 'createExchange']);
        $definition->addArgument(new Reference($channelAlias));
        $definition->addArgument($exchangeName);
        $definition->addArgument($config['type']);
        $definition->addArgument($this->getExchangeFlagsValue($config));
        $definition->addArgument($config['arguments']);

        $exchangeId = sprintf('tree_house.queue.exchange.%s', $name);
        $container->setDefinition($exchangeId, $definition);

        $this->exchanges[$name] = $exchangeId;

        if (isset($config['dlx']['enable']) && $config['dlx']['enable']) {
            if (!isset($config['dlx']['name'])) {
                $config['dlx']['name'] = sprintf('%s.dead', $exchangeName);
            }

            $dlxName = $config['dlx']['name'];
            $dlxId = $this->createExchangeDefinition($dlxName, $config['dlx'], $container);

            $this->dlxs[$name] = $dlxId;
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
        $queue = $config['queue'];
        $queueId = $this->createQueueDefinition($name, $queue, $container);

        // create the message provider
        $definition = new Definition($container->getParameter('tree_house.queue.provider.class'));
        $definition->addArgument(new Reference($queueId));
        $providerId = sprintf('tree_house.queue.provider.%s', $name);
        $container->setDefinition($providerId, $definition);

        // create the processor
        $processorId = $this->createProcessorDefinition($name, $config, $container);

        // create the consumer
        $definition = new DefinitionDecorator('tree_house.queue.consumer.prototype');
        $definition->addArgument(new Reference($providerId));
        $definition->addArgument(new Reference($processorId));

        $consumerId = sprintf('tree_house.queue.consumer.%s', $name);
        $container->setDefinition($consumerId, $definition);

        $this->consumers[$name] = $consumerId;

        return $consumerId;
    }

    /**
     * @param string           $name
     * @param array            $queue
     * @param ContainerBuilder $container
     *
     * @return string
     */
    private function createQueueDefinition($name, array $queue, ContainerBuilder $container)
    {
        $amqpFactory = new Reference('tree_house.amqp.factory');

        $connection = $queue['connection'] ?: $container->getParameter('tree_house.queue.default_connection');
        $channelId = sprintf('tree_house.queue.channel.%s', $connection);
        $arguments = $queue['arguments'];

        // if there is an exchange with the same name, and it has a DLX configured, set this in the arguments
        if (!array_key_exists('x-dead-letter-exchange', $arguments) && $dlxId = $this->getDeadLetterExchange($name)) {
            $dlx = $container->getDefinition($dlxId);
            $arguments['x-dead-letter-exchange'] = $dlx->getArgument(1);
        }

        // create queue
        $definition = new Definition($container->getParameter('tree_house.queue.queue.class'));
        $definition->setFactory([$amqpFactory, 'createQueue']);
        $definition->addArgument(new Reference($channelId));
        $definition->addArgument($queue['name']);
        $definition->addArgument($this->getQueueFlagsValue($queue));
        $definition->addArgument($arguments);

        if (empty($queue['bindings'])) {
            // bind to the same exchange
            $queue['bindings'][] = [
                'exchange' => $name,
                'routing_keys' => [],
                'arguments' => [],
            ];
        }

        foreach ($queue['bindings'] as $binding) {
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

        $this->queues[$name] = $queueId;

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
        $service = $this->decorateRetryProcessor($name, $config['retry'], $service);

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
     *
     * @return Definition
     */
    private function decorateRetryProcessor($name, array $config, $service)
    {
        // skip if we only use 1 attempt
        if ($config['attempts'] < 2) {
            return $service;
        }

        // decorate the processor
        $strategy = $this->createRetryStrategyDefinition($name, $config['strategy']);

        $retry = new Definition(RetryProcessor::class);
        $retry->addArgument(is_string($service) ? new Reference($service) : $service);
        $retry->addArgument(is_string($strategy) ? new Reference($strategy) : $strategy);
        $retry->addArgument(new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $retry->addMethodCall('setMaxAttempts', [$config['attempts']]);

        return $retry;
    }

    /**
     * @param string $name
     * @param array  $config
     *
     * @return Definition
     */
    private function createRetryStrategyDefinition($name, array $config)
    {
        switch ($config['type']) {
            case 'backoff':
                $strategy = new Definition(BackoffStrategy::class);
                $strategy->addArgument(new Reference(sprintf('tree_house.queue.publisher.%s', $name)));

                break;
            case 'deprioritize':
                $strategy = new Definition(DeprioritizeStrategy::class);
                $strategy->addArgument(new Reference(sprintf('tree_house.queue.publisher.%s', $name)));

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

    /**
     * @param string $name
     *
     * @return string|null
     */
    private function getDeadLetterExchange($name)
    {
        if (isset($this->dlxs[$name])) {
            return $this->dlxs[$name];
        }

        return null;
    }
}
