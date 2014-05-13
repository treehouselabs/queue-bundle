Feature: config
  In order to use the bundle
  As a developer
  I need to be able to configure it

  Scenario: Shortest connection config
    Given the config:
      """
      tree_house_queue:
        connection: localhost
      """
    When I build the container
    Then I should have a connection named "default"
    And the "default" connection should have host "localhost"

  Scenario: Regular connection config
    Given the config:
      """
      tree_house_queue:
        connection:
          host: localhost
      """
    When I build the container
    Then I should have a connection named "default"
    And the "default" connection should have host "localhost"

  Scenario: Multiple connections
    Given the config:
      """
      tree_house_queue:
        connections:
          conn1:
            host: localhost
          conn2:
            host: rabbitmqhost
            port: 123
      """
    When I build the container
    Then I should have a connection named "conn1"
    And I should have a connection named "conn2"
    And the "conn1" connection should have host "localhost"
    And the "conn2" connection should have host "rabbitmqhost"
    And the "conn2" connection should have port "123"

  Scenario: Publishers config
    Given the config:
      """
      tree_house_queue:
        connection: localhost
        publishers:
          process1:
            serializer: php
            exchange: direct
          pubsub:
            serializer: json
            exchange:
              type: fanout
              durable: false
      """
    When I build the container
    Then I should have a publisher named "process1"
    And the "process1" publisher should serialize using php
    And I should have an exchange named "process1"
    And the "process1" exchange should be of type "direct"
    And the "process1" exchange should be durable
    And I should have a publisher named "pubsub"
    And the "pubsub" publisher should serialize using json
    And the "pubsub" exchange should be of type "fanout"
    And the "pubsub" exchange should not be durable

  Scenario: Consumers config
    Given the config:
      """
      services:
        test_processor:
          class: TreeHouse\FunctionalTestBundle\Queue\Processor\TestProcessor

      tree_house_queue:
        connection: localhost
        consumers:
          process1:
            processor: TreeHouse\FunctionalTestBundle\Queue\Processor\TestProcessor
          pubsub:
            processor: @test_processor
            attempts: 1
            queue:
              durable: false
              exclusive: true
              auto_delete: true
      """
    When I build the container
    Then I should have a consumer named "process1"
    And the "process1" consumer should process using the "TreeHouse\FunctionalTestBundle\Queue\Processor\TestProcessor" class
#    And the "process1" consumer should get 3 attempts
    And I should have a queue named "process1"
    And the "process1" queue should be durable
    And I should have a consumer named "pubsub"
    And the "pubsub" consumer should process using the "test_processor" service
#    And the "pubsub" consumer should get 1 attempt
    And I should have a queue named "pubsub"
    And the "pubsub" queue should not be durable
    And the "pubsub" queue should be exclusive
    And the "pubsub" queue should auto-delete

  Scenario: Queue config
    Given the config:
      """
      tree_house_queue:
        connection: localhost
        queues:
          pubsub1:
            durable: false
            passive: false
            exclusive: true
            auto_delete: true
            bindings:
              exchange: pubsub
      """
    When I build the container
    Then I should have a queue named "pubsub1"
    And the "pubsub1" queue should not be durable
    And the "pubsub1" queue should not be passive
    And the "pubsub1" queue should be exclusive
    And the "pubsub1" queue should auto-delete
