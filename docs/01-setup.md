## Installation

Add dependency:

```sh
composer require treehouselabs/queue-bundle:^1.0
```

Enable bundle:

```php
$bundles[] = new TreeHouse\QueueBundle\TreeHouseQueueBundle();
```

## Configuration

```yaml
# app/config/config.yml
tree_house_queue:
  driver: amqp

  # configure one or more connections
  connection:
    host: localhost
    user: admin
    pass: 1234

  # publishers automatically configure/create exchanges
  publishers:
    insert:
      composer: json
      exchange:
        type: direct
        # don't create a DLX counterpart for this exchange, disabling dead-lettering
        dlx: false
    update:
      serializer: json
      exchange: fanout
    delete:
      composer: json
      exchange: fanout

  # consumers automatically configure/create queues
  consumers:
    insert:
      # can be a FQCN or a service
      processor: '@app.queue.processor.insert'
      queue:
        name: 'insert'
    update:
      processor: '@app.queue.processor.update'
      queue:
        name: 'update'
        durable: true
        dlx: 'update.dead'
      # configuring retry automatically sets up an exchange/queue to retry failed messages
      retry:
        attempts: 2

  # custom exchanges
  exchanges:
    custom_exchg:
      type: topic
      durable: false
      auto_delete: true
      passive: true
      internal: true
      delay: false
      dlx: false
      arguments:
        x-foo: bar

  # custom queues
  queues:
    custom_queue:
      type: topic
      durable: false
      auto_delete: true
      passive: true
      exclusive: true
      dlx: dead_letter_exchange_name
      bindings:
        -
          exchange: foo
          routing_key: bar
          arguments:
            x-foo: bar
```
