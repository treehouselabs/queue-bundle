Feature: publish
  In order to use a queue
  As a developer
  I need to be able to create and publish messages

  Background:
    Given the config:
      """
      services:
        test_processor:
          class: TreeHouse\FunctionalTestBundle\Queue\Processor\TestProcessor

      tree_house_queue:
        driver: amqp
        connection: localhost
        publishers:
          process1:
            serializer: php
            exchange: direct
          pubsub:
            serializer: json
            exchange:
              type: fanout

        consumers:
          process1:
            processor: TreeHouse\FunctionalTestBundle\Queue\Processor\TestProcessor
            attempts: 3
          pubsub:
            processor: @test_processor
            attempts: 5
            queue:
              durable: false
              passive: true
              exclusive: true
              auto_delete: true

        queues:
          q1:
            binding:
              exchange: xchg1
              routing_keys:
                - foo
                - bar
          pubsub1:
            durable: false
            passive: true
            exclusive: true
            auto_delete: true
            bindings:
              exchange: pubsub
          pubsub2:
            durable: true
            bindings:
              exchange: pubsub
      """
    And I build the container

  Scenario: create a message
    Given a message publisher for process1
    When I create a message with payload "test"
    Then a message should be returned
    And the message should have the body "test"

  Scenario: publish a message
    Given a message publisher for process1
    When I publish a message with payload "test" to the "process1" exchange
    Then the message should be published
