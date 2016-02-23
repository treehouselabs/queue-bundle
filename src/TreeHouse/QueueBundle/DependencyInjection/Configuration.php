<?php

namespace TreeHouse\QueueBundle\DependencyInjection;

use TreeHouse\Queue\Amqp\ExchangeInterface as Exchg;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Form\Exception\InvalidConfigurationException;

class Configuration implements ConfigurationInterface
{
    /**
     * @inheritdoc
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('tree_house_queue');
        $rootNode
            ->children()
                ->enumNode('driver')
                    ->values(['amqp', 'php-amqplib'])
                    ->defaultValue('amqp')
                    ->validate()
                        ->ifTrue(function ($value) {
                            return $value === 'php-amqplib';
                        })
                        ->thenInvalid('Driver for php-amqplib is not yet implemented')
                    ->end()
                ->end()
                ->booleanNode('auto_flush')
                    ->defaultTrue()
                    ->info('Whether to automatically flush the Doctrine object manager when processing messages')
                ->end()
            ->end()
        ;

        $this->addConnectionsSection($rootNode);
        $this->addPublishersSection($rootNode);
        $this->addConsumersSection($rootNode);
        $this->addQueuesSection($rootNode);

        return $treeBuilder;
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addConnectionsSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('connection')
            ->children()
                ->scalarNode('default_connection')->defaultNull()->end()
                ->arrayNode('connections')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->cannotBeEmpty()
                    ->info('List of connections. The key becomes the connection name. If no key is set (ie: a numeric list) the name is set to "default"')
                    ->example(<<<EOF
# long
connections:
  conn1:
    host: localhost
  conn2:
    host: otherhost

# short, will be named default
connection:
    host: rabbitmqhost
    port: 1234

# shorter, will be named default
connection: rabbitmqhost

# mixing it:
connections:
  conn1: localhost
  conn2:
    host: otherhost
    port: 1234

# WRONG: connections need to have a name
connections:
  -
    host: localhost
EOF
                    )

                    ->beforeNormalization()
                        ->ifArray()
                        ->then(function ($value) {
                            $conns = [];
                            foreach ($value as $key => $conn) {
                                // it was either a string, or multiple key-less entries
                                if (is_numeric($key)) {
                                    if (sizeof($value) > 1) {
                                        throw new InvalidConfigurationException(
                                            sprintf('Connections need to be a list of key-value pairs')
                                        );
                                    }

                                    // key becomes 'default'
                                    $key = 'default';
                                }

                                // string becomes the host
                                if (is_string($conn)) {
                                    $conn = [
                                        'host' => $conn
                                    ];
                                }

                                $conns[$key] = $conn;
                            }

                            return $conns;
                        })
                    ->end()
                    ->prototype('array')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('host')->defaultValue('localhost')->end()
                            ->scalarNode('port')->defaultValue(5672)->end()
                            ->scalarNode('user')->defaultValue('guest')->end()
                            ->scalarNode('pass')->defaultValue('guest')->end()
                            ->scalarNode('vhost')->defaultValue('/')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addPublishersSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('publisher')
            ->children()
                ->arrayNode('publishers')
                    ->prototype('array')
                        ->addDefaultsIfNotSet()
                        ->children()
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
                            ->end()

                            ->scalarNode('composer')
                                ->defaultValue('%tree_house.queue.composer.default.class%')
                            ->end()

                            ->arrayNode('exchange')
                                ->addDefaultsIfNotSet()
                                ->beforeNormalization()
                                    ->ifString()
                                    ->then(function ($value) {
                                        return [
                                            'type' => $value,
                                        ];
                                    })
                                ->end()
                                ->children()
                                    ->enumNode('type')
                                        ->values([Exchg::TYPE_DIRECT, Exchg::TYPE_FANOUT, Exchg::TYPE_TOPIC, Exchg::TYPE_HEADERS])
                                        ->defaultValue(Exchg::TYPE_DIRECT)
                                    ->end()
                                    ->scalarNode('connection')->defaultNull()->end()
                                    ->booleanNode('durable')->defaultTrue()->end()
                                    ->booleanNode('passive')->defaultFalse()->end()
                                    ->booleanNode('auto_delete')->defaultFalse()->end()
                                    ->booleanNode('internal')->defaultFalse()->end()
                                    ->booleanNode('nowait')->defaultFalse()->end()
                                    ->arrayNode('arguments')
                                        ->normalizeKeys(false)
                                        ->prototype('scalar')
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addConsumersSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('consumer')
            ->children()
                ->arrayNode('consumers')
                    ->prototype('array')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('processor')
                                ->validate()
                                    ->ifTrue(function ($value) {
                                        substr($value, 0, 1) !== '@' && !class_exists($value);
                                    })
                                    ->thenInvalid('Processor class "%s" does not exist')
                                ->end()
                            ->end()

                            ->arrayNode('queue')
                                ->addDefaultsIfNotSet()
                                ->fixXmlConfig('binding')
                                ->children()
                                    ->scalarNode('name')->defaultNull()->end()
                                    ->scalarNode('connection')->defaultNull()->end()
                                    ->booleanNode('durable')->defaultTrue()->end()
                                    ->booleanNode('passive')->defaultFalse()->end()
                                    ->booleanNode('exclusive')->defaultFalse()->end()
                                    ->booleanNode('auto_delete')->defaultFalse()->end()
                                    ->arrayNode('bindings')
                                        ->requiresAtLeastOneElement()
                                        ->prototype('array')
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
                                            ->end()

                                            ->addDefaultsIfNotSet()
                                            ->fixXmlConfig('routing_key')
                                            ->children()
                                                ->scalarNode('exchange')->isRequired()->end()
                                                ->arrayNode('routing_keys')
                                                    ->prototype('scalar')->defaultValue([])->end()
                                                ->end()
                                                ->arrayNode('arguments')
                                                    ->normalizeKeys(false)
                                                    ->prototype('scalar')->defaultValue([])->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                    ->arrayNode('arguments')
                                        ->normalizeKeys(false)
                                        ->prototype('scalar')->defaultValue([])->end()
                                    ->end()
                                ->end()
                            ->end()

                            ->scalarNode('attempts')
                                ->defaultValue(2)
                                ->validate()
                                    ->ifTrue(function ($value) {
                                        return !($value > 0);
                                    })
                                    ->thenInvalid('Expecting a positive number, got "%s"')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param ArrayNodeDefinition $rootNode
     */
    private function addQueuesSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('queue')
            ->children()
                ->arrayNode('queues')
                    ->prototype('array')
                        ->fixXmlConfig('binding')
                        ->children()
                            ->scalarNode('name')->defaultNull()->end()
                            ->scalarNode('connection')->defaultNull()->end()
                            ->booleanNode('durable')->defaultTrue()->end()
                            ->booleanNode('passive')->defaultFalse()->end()
                            ->booleanNode('exclusive')->defaultFalse()->end()
                            ->booleanNode('auto_delete')->defaultFalse()->end()
                            ->arrayNode('bindings')
                                ->requiresAtLeastOneElement()
                                ->prototype('array')
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
                                    ->end()
                                    ->addDefaultsIfNotSet()
                                    ->fixXmlConfig('routing_key')
                                    ->children()
                                        ->scalarNode('exchange')->isRequired()->end()
                                        ->arrayNode('routing_keys')
                                            ->prototype('scalar')->defaultValue([])->end()
                                        ->end()
                                        ->arrayNode('arguments')
                                            ->normalizeKeys(false)
                                            ->prototype('scalar')->defaultValue([])->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('arguments')
                                ->normalizeKeys(false)
                                ->prototype('scalar')->defaultValue([])->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
