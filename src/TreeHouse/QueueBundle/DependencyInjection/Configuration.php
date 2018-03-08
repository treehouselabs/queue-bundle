<?php

namespace TreeHouse\QueueBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use TreeHouse\Queue\Amqp\ExchangeInterface as Exchg;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * @inheritdoc
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('tree_house_queue');
        $children = $rootNode->children();

        $children
            ->enumNode('driver')
            ->values(['amqp', 'php-amqplib'])
            ->defaultValue('amqp')
            ->validate()
            ->ifTrue(function ($value) {
                return $value === 'php-amqplib';
            })
            ->thenInvalid('Driver for php-amqplib is not yet implemented')
        ;

        $children
            ->booleanNode('auto_flush')
            ->defaultTrue()
            ->info('Whether to automatically flush the Doctrine object manager when processing messages')
        ;

        $this->addConnectionsSection($rootNode);
        $this->addPublishersSection($rootNode);
        $this->addConsumersSection($rootNode);

        /** @var ArrayNodeDefinition $exchanges */
        $exchanges = $rootNode
            ->fixXmlConfig('exchange')
            ->children()
            ->arrayNode('exchanges')
            ->useAttributeAsKey('name')
            ->prototype('array')
        ;

        $this->addExchangeSection($exchanges);

        /** @var ArrayNodeDefinition $queues */
        $queues = $rootNode
            ->fixXmlConfig('queue')
            ->children()
            ->arrayNode('queues')
            ->useAttributeAsKey('name')
            ->prototype('array')
        ;

        $this->addQueueSection($queues);

        return $treeBuilder;
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addConnectionsSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode->fixXmlConfig('connection');
        $children = $rootNode->children();

        $children
            ->scalarNode('default_connection')
            ->defaultNull()
        ;

        /** @var ArrayNodeDefinition $connections */
        $connections = $children
            ->arrayNode('connections')
            ->isRequired()
            ->requiresAtLeastOneElement()
            ->useAttributeAsKey('name')
            ->info('List of connections. The key becomes the connection name.')
            ->example(<<<EOF
# long
connections:
  conn1:
    host: localhost
  conn2:
    host: otherhost

# short
connection:
  host: rabbitmqhost
  port: 1234

# shorter
connection: rabbitmqhost

# mixing it:
connections:
  conn1: localhost
  conn2:
    host: otherhost
    port: 1234

# numeric name index
connections:
  -
    host: localhost

# named index
connections:
  -
    name: conn1
    host: localhost
EOF
            )
        ;

        $connections
            ->beforeNormalization()
            ->ifArray()
            ->then(function ($value) {
                $conns = [];
                foreach ($value as $key => $conn) {
                    // string becomes the host
                    if (is_string($conn)) {
                        $conn = [
                            'host' => $conn,
                        ];
                    }

                    if (!isset($conn['name'])) {
                        $conn['name'] = $key;
                    }

                    $conns[$key] = $conn;
                }

                return $conns;
            })
        ;

        /** @var ArrayNodeDefinition $prototype */
        $prototype = $connections->prototype('array');
        $prototype->addDefaultsIfNotSet();

        $connection = $prototype->children();
        $connection->scalarNode('name');
        $connection->scalarNode('host');
        $connection->integerNode('port')->defaultValue(5672);
        $connection->scalarNode('user')->defaultValue('guest');
        $connection->scalarNode('pass')->defaultValue('guest');
        $connection->scalarNode('vhost')->defaultValue('/');

        $params = $connection->arrayNode('params');
        $params->prototype('scalar');
        $params->defaultValue([
            'heartbeat' => 60
        ]);
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addPublishersSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode->fixXmlConfig('publisher');
        $children = $rootNode->children();

        /** @var ArrayNodeDefinition $publishers */
        $publishers = $children->arrayNode('publishers')->useAttributeAsKey('name')->prototype('array');
        $publishers->addDefaultsIfNotSet();

        $publisher = $publishers->children();
        $publisher->scalarNode('name');
        $publisher
            ->scalarNode('serializer')
            ->defaultValue('@tree_house.queue.serializer.php')
            ->beforeNormalization()
                ->ifInArray(['php', 'json', 'doctrine'])
                ->then(function ($value) {
                    return sprintf('@tree_house.queue.serializer.%s', $value);
                })
            ->end()
            ->validate()
                ->ifTrue(function ($value) {
                    substr($value, 0, 1) !== '@' && !class_exists($value);
                })
                ->thenInvalid('Serializer class "%s" does not exist')
            ->end()
        ;

        $publisher
            ->scalarNode('composer')
            ->defaultValue('%tree_house.queue.composer.default.class%')
        ;

        $exchange = $publisher->arrayNode('exchange');
        $this->addExchangeSection($exchange);
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addConsumersSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode->fixXmlConfig('consumer');
        $children = $rootNode->children();

        /** @var ArrayNodeDefinition $consumers */
        $consumers = $children->arrayNode('consumers')->useAttributeAsKey('name')->prototype('array');
        $consumers->addDefaultsIfNotSet();

        $consumer = $consumers->children();
        $consumer->scalarNode('name');

        // processor
        $consumer
            ->scalarNode('processor')
            ->validate()
            ->ifTrue(function ($value) {
                substr($value, 0, 1) !== '@' && !class_exists($value);
            })
            ->thenInvalid('Processor class "%s" does not exist')
        ;

        // queue
        $queue = $consumer->arrayNode('queue');
        $this->addQueueSection($queue);

        // retry
        $retry = $consumer->arrayNode('retry');
        $retry
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
            ->ifTrue(function ($value) {
                return is_scalar($value);
            })
            ->then(function ($attempts) {
                if (!is_numeric($attempts)) {
                    throw new InvalidConfigurationException('When using a scalar for "retry", it must be numeric, eg: "retry: 3"');
                }

                return [
                    'attempts' => (int) $attempts,
                ];
            })
        ;

        $retryConfig = $retry->children();
        $retryConfig
            ->integerNode('attempts')
            ->defaultValue(1)
            ->validate()
            ->ifTrue(function ($value) {
                return !($value > 0);
            })
            ->thenInvalid('Expecting a positive number, got "%s"')
        ;
        $retryConfig
            ->scalarNode('publisher')
            ->defaultNull()
            ->info('Name of the publisher to use for retrying messages. Defaults to the same name as the consumer')
        ;

        // retry strategy
        $strategy = $retryConfig->arrayNode('strategy');
        $strategy
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
            ->ifString()
            ->then(function ($type) {
                return [
                    'type' => $type,
                ];
            })
        ;

        $strategyConfig = $strategy->children();
        $strategyConfig
            ->enumNode('type')
            ->values(['backoff', 'deprioritize'])
            ->defaultValue('backoff')
        ;
    }

    /**
     * @param ArrayNodeDefinition $node
     * @param bool                $includeDlx
     * @param bool                $includeDelay
     */
    private function addExchangeSection(ArrayNodeDefinition $node, $includeDlx = true, $includeDelay = true)
    {
        $node->addDefaultsIfNotSet();
        $node
            ->beforeNormalization()
            ->ifString()
            ->then(function ($value) {
                return [
                    'type' => $value,
                ];
            })
        ;

        $exchange = $node->children();
        $exchange
            ->scalarNode('name')
            ->defaultNull()
            ->info('The name to create the exchange with in the AMQP broker')
        ;
        $exchange
            ->enumNode('type')
            ->values([Exchg::TYPE_DIRECT, Exchg::TYPE_FANOUT, Exchg::TYPE_TOPIC, Exchg::TYPE_HEADERS])
            ->defaultValue(Exchg::TYPE_DIRECT)
            ->info('The exchange type')
        ;

        $exchange
            ->booleanNode('auto_declare')
            ->info('Whether to automatically declare the exchange on cache warmup. Only enable this when you have configure access to the exchange')
        ;

        $exchange->scalarNode('connection')->defaultNull();
        $exchange->booleanNode('durable')->defaultTrue();
        $exchange->booleanNode('passive')->defaultFalse();
        $exchange->booleanNode('auto_delete')->defaultFalse();
        $exchange->booleanNode('internal')->defaultFalse();
        $exchange->booleanNode('nowait')->defaultFalse();
        $exchange
            ->arrayNode('arguments')
            ->normalizeKeys(false)
            ->prototype('scalar')
            ->defaultValue([])
        ;

        if ($includeDelay) {
            $exchange
                ->booleanNode('delay')
                ->defaultTrue()
                ->info('Whether to enable delayed messages for this exchange')
            ;
        }

        if ($includeDlx) {
            $dlx = $exchange->arrayNode('dlx');
            $dlx->info('Create a dead letter exchange for this exchange');
            $dlx->canBeDisabled();

            // copy the entire exchange configuration here
            $this->addExchangeSection($dlx, false, false);

            $queue = $dlx
                ->children()
                ->arrayNode('queue')
            ;
            $this->addQueueSection($queue);
        }
    }

    /**
     * @param ArrayNodeDefinition $node
     */
    private function addQueueSection(ArrayNodeDefinition $node)
    {
        $node->addDefaultsIfNotSet();
        $node->fixXmlConfig('binding');

        $queue = $node->children();

        $queue
            ->booleanNode('auto_declare')
            ->info('Whether to automatically declare the queue on cache warmup. Only enable this when you have configure access to the queue')
        ;

        $queue->scalarNode('name')->defaultNull();
        $queue->scalarNode('connection')->defaultNull();
        $queue->booleanNode('durable')->defaultTrue();
        $queue->booleanNode('passive')->defaultFalse();
        $queue->booleanNode('exclusive')->defaultFalse();
        $queue->booleanNode('auto_delete')->defaultFalse();
        $queue
            ->scalarNode('dlx')
            ->defaultNull()
            ->info('The name of the dead letter exchange that this queue should link to')
        ;

        $queue
            ->arrayNode('arguments')
            ->normalizeKeys(false)
            ->prototype('scalar')
            ->defaultValue([])
        ;

        $bindings = $queue->arrayNode('bindings');
        $bindings->requiresAtLeastOneElement();

        /** @var ArrayNodeDefinition $binding */
        $binding = $bindings->prototype('array');
        $binding
            ->beforeNormalization()
            ->always()
            ->then(function ($binding) {
                // use string as exchange
                if (is_string($binding)) {
                    $binding = ['exchange' => $binding];
                }

                // if multiple routing keys are given, make a copy for each one
                if (!isset($binding['routing_keys'])) {
                    $binding['routing_keys'] = isset($binding['routing_key']) ? $binding['routing_key'] : [];
                    unset($binding['routing_key']);
                }

                if (is_scalar($binding['routing_keys'])) {
                    $binding['routing_keys'] = [$binding['routing_keys']];
                }

                return $binding;
            })
        ;

        $binding
            ->addDefaultsIfNotSet()
            ->fixXmlConfig('routing_key')
        ;

        $bindingOptions = $binding->children();
        $bindingOptions
            ->scalarNode('exchange')
            ->isRequired()
        ;
        $bindingOptions
            ->arrayNode('routing_keys')
            ->prototype('scalar')
            ->defaultValue([])
        ;
        $bindingOptions
            ->arrayNode('arguments')
            ->normalizeKeys(false)
            ->prototype('scalar')
            ->defaultValue([])
        ;
    }
}
