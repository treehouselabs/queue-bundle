<?php

namespace TreeHouse\QueueBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class TreeHouseQueueExtension extends Extension
{
    /**
     * @inheritdoc
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $this->loadDriver($config, $container);
        $this->loadConnections($config, $container);
        $this->loadPublishers($config, $container);
        $this->loadConsumers($config, $container);
        $this->loadQueues($config, $container);

        if (!$config['auto_flush']) {
            $container->removeDefinition('tree_house.queue.event_listener.queue');
        }
   }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    protected function loadDriver(array $config, ContainerBuilder $container)
    {
        $container->setParameter('tree_house.queue.driver', $config['driver']);
        $container->setAlias(
            'tree_house.queue.factory',
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
    protected function loadConnections(array $config, ContainerBuilder $container)
    {
        foreach ($config['connections'] as $name => $connection) {
            // create connection
            $definition = new Definition($container->getParameter('tree_house.queue.connection.class'));
            $definition->setFactoryService('tree_house.queue.factory');
            $definition->setFactoryMethod('createConnection');
            $definition->addArgument($connection['host']);
            $definition->addArgument((integer) $connection['port']);
            $definition->addArgument($connection['user']);
            $definition->addArgument($connection['pass']);
            $definition->addArgument($connection['vhost']);
            // TODO lazy
//            $definition->setLazy(true);

            $connectionId = sprintf('tree_house.queue.connection.%s', $name);
            $container->setDefinition($connectionId, $definition);

            // create channel
            $definition = new Definition($container->getParameter('tree_house.queue.channel.class'));
            $definition->setFactoryService('tree_house.queue.factory');
            $definition->setFactoryMethod('createChannel');
            $definition->addArgument(new Reference($connectionId));
            $definition->setPublic(false);
            // TODO lazy
//            $definition->setLazy(true);

            // TODO set qos and prefetch stuff

            $channelId = sprintf('tree_house.queue.channel.%s', $name);
            $container->setDefinition($channelId, $definition);
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
    protected function loadPublishers(array $config, ContainerBuilder $container)
    {
        foreach ($config['publishers'] as $name => $publisher) {
            // get the right channel for the exchange
            $exchange = $publisher['exchange'];
            $connection = $exchange['connection'] ?: $container->getParameter('tree_house.queue.default_connection');
            $channelId = sprintf('tree_house.queue.channel.%s', $connection);

            // create exchange
            $definition = new Definition($container->getParameter('tree_house.queue.exchange.class'));
            $definition->setFactoryService('tree_house.queue.factory');
            $definition->setFactoryMethod('createExchange');
            $definition->addArgument(new Reference($channelId));
            $definition->addArgument($name);
            $definition->addArgument($exchange['type']);
            $definition->addArgument($this->getExchangeFlagsValue($exchange));
            $definition->addArgument($exchange['arguments']);
            // TODO lazy
//            $definition->setLazy(true);

            $exchangeId = sprintf('tree_house.queue.exchange.%s', $name);
            $container->setDefinition($exchangeId, $definition);

            // add alias if connection is named differently than exchange
            if ($name !== $connection) {
                $container->setAlias(sprintf('tree_house.queue.channel.%s', $name), $channelId);
            }

            // message serializer
            $serializerId = sprintf('tree_house.queue.serializer.%s', $name);
            if (substr($publisher['serializer'], 0, 1) === '@') {
                $container->setAlias($serializerId, ltrim($publisher['serializer'], '@'));
            } else {
                $serializer = new Definition($publisher['serializer']);
                $container->setDefinition($serializerId, $serializer);
            }

            // message composer
            $composerId = sprintf('tree_house.queue.composer.%s', $name);
            $composerClass = $publisher['composer_class'];
            if (substr($composerClass, 0, 1) === '%') {
                $composerClass = $container->getParameter(substr($composerClass, 1, -1));
            }
            $composer = new Definition($composerClass);
            $composer->addArgument(new Reference($serializerId));
            $container->setDefinition($composerId, $composer);

            // create publisher
            $publisherId = sprintf('tree_house.queue.publisher.%s', $name);
            $publisher = new Definition($container->getParameter('tree_house.queue.publisher.class'));
            $publisher->addArgument(new Reference($exchangeId));
            $publisher->addArgument(new Reference($composerId));

            $container->setDefinition($publisherId, $publisher);
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    protected function loadConsumers(array $config, ContainerBuilder $container)
    {
        foreach ($config['consumers'] as $name => $consumer) {
            // get the right channel for the queue
            $queue = $consumer['queue'];
            $queueId = $this->loadQueue($name, $queue, $container);

            // create the message provider
            $definition = new Definition($container->getParameter('tree_house.queue.provider.class'));
            $definition->addArgument(new Reference($queueId));
            $providerId = sprintf('tree_house.queue.provider.%s', $name);
            $container->setDefinition($providerId, $definition);

            // create the processor
            $processorId = sprintf('tree_house.queue.processor.%s', $name);

            if (substr($consumer['processor'], 0, 1) === '@') {
                $serviceId = ltrim($consumer['processor'], '@');
//                if ($consumer['attempts'] > 1) {
//                    $retry = new Definition(RetryProcessor::class);
//                    $retry->addArgument(new Reference($serviceId));
//                    $retry->addArgument(new Reference(sprintf('tree_house.queue.publisher.%s', $name)));
//                    $retry->addMethodCall('setMaxAttempts', [$consumer['attempts']]);
//                    $container->setDefinition($processorId, $retry);
//                } else {
                    $container->setAlias($processorId, $serviceId);
//                }
            } else {
//                if ($consumer['attempts'] > 1) {
//                    $processor = new Definition($consumer['processor']);
//                    $processor->setPublic(false);
//
//                    $retry = new Definition(RetryProcessor::class);
//                    $retry->addArgument($processor);
//                    $retry->addArgument(new Reference(sprintf('tree_house.queue.publisher.%s', $name)));
//                    $retry->addMethodCall('setMaxAttempts', [$consumer['attempts']]);
//                    $container->setDefinition($processorId, $retry);
//                } else {
                    $container->setDefinition($processorId, new Definition($consumer['processor']));
//                }
            }

            // create the consumer
            $definition = new Definition($container->getParameter('tree_house.queue.consumer.class'));
            $definition->addArgument(new Reference($providerId));
            $definition->addArgument(new Reference($processorId));
            // TODO lazy
//            $definition->setLazy(true);

            $consumerId = sprintf('tree_house.queue.consumer.%s', $name);
            $container->setDefinition($consumerId, $definition);
        }
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    protected function loadQueues(array $config, ContainerBuilder $container)
    {
        foreach ($config['queues'] as $name => $queue) {
            $this->loadQueue($name, $queue, $container);
        }
    }

    /**
     * @param string           $name
     * @param array            $queue
     * @param ContainerBuilder $container
     *
     * @return string
     */
    protected function loadQueue($name, array $queue, ContainerBuilder $container)
    {
        $connection = $queue['connection'] ? : $container->getParameter('tree_house.queue.default_connection');
        $channelId  = sprintf('tree_house.queue.channel.%s', $connection);

        // create queue
        $definition = new Definition($container->getParameter('tree_house.queue.queue.class'));
        $definition->setFactoryService('tree_house.queue.factory');
        $definition->setFactoryMethod('createQueue');
        $definition->addArgument(new Reference($channelId));
        $definition->addArgument($queue['name']);
        $definition->addArgument($this->getQueueFlagsValue($queue));
        $definition->addArgument($queue['arguments']);
        // TODO lazy
//        $definition->setLazy(true);

        if (empty($queue['bindings'])) {
            // bind to the same exchange
            $queue['bindings'][] = [
                'exchange'     => $name,
                'routing_keys' => [],
                'arguments'    => [],
            ];
        }

        foreach ($queue['bindings'] as $binding) {
            if (empty($binding['routing_keys'])) {
                $binding['routing_keys'] = [null];
            }

            foreach ($binding['routing_keys'] as $routingKey) {
                $definition->addMethodCall('bind', [$binding['exchange'], $routingKey, $binding['arguments']]);
            }
        }

        $queueId = sprintf('tree_house.queue.queue.%s', $name);
        $container->setDefinition($queueId, $definition);

        return $queueId;
    }

    /**
     * @param array $exchange
     *
     * @return integer
     */
    protected function getExchangeFlagsValue(array $exchange)
    {
        $flags = AMQP_NOPARAM;

        if ($exchange['durable']) {
            $flags |= AMQP_DURABLE;
        }

        if ($exchange['passive']) {
            $flags |= AMQP_PASSIVE;
        }

        return $flags;
    }

    /**
     * @param array $queue
     *
     * @return integer
     */
    protected function getQueueFlagsValue(array $queue)
    {
        $flags = AMQP_NOPARAM;

        if ($queue['durable']) {
            $flags |= AMQP_DURABLE;
        }

        if ($queue['passive']) {
            $flags |= AMQP_PASSIVE;
        }

        if ($queue['exclusive']) {
            $flags |= AMQP_EXCLUSIVE;
        }

        if ($queue['auto_delete']) {
            $flags |= AMQP_AUTODELETE;
        }

        return $flags;
    }
}
