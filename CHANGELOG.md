Changelog
=========

This changelog mostly documents breaking changes and deprecations.
For a complete list of releases, see the [releases page][0].

[0]: https://github.com/treehouselabs/queue-bundle/releases


## v0.1.0

### Changes
* Added Symfony3 support
* Added configuration to specify the strategy which retries are handled with
* Added cache warmer
* Added configuration to automatically declare dead letter exchanges

### Breaking changes
* Updated required PHP version to 5.6
* Updated `treehouselabs/queue` dependency to `0.1.0`. See those [release notes](https://github.com/treehouselabs/queue-bundle/releases/tag/v0.1.0)
  for an overview of breaking changes.
* Renamed `tree_house.queue.event_listener.queue` to `tree_house.queue.event_listener.flush`
* Replaced `attempts` config in consumers with a more extended `retry` config:

  **Before:**
  ```yaml
  consumers:
    foo:
      attempts: 2
  ```

  **After:**
  ```yaml
  consumers:
    foo:
      retry: 2
  ```


## v0.0.1

First alpha release
