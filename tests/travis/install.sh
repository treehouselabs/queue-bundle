#!/usr/bin/env bash
trap 'exit' ERR

cd "$(dirname "$0")"/../../

wget http://nz.archive.ubuntu.com/ubuntu/pool/universe/libr/librabbitmq/librabbitmq4_0.7.1-1_amd64.deb
sudo dpkg -i librabbitmq4_0.7.1-1_amd64.deb

wget http://nz.archive.ubuntu.com/ubuntu/pool/universe/libr/librabbitmq/librabbitmq-dev_0.7.1-1_amd64.deb
sudo dpkg -i librabbitmq-dev_0.7.1-1_amd64.deb

sudo apt-get install php-pear
echo yes | pecl install amqp-1.7.0alpha2

composer self-update

if [ "$SYMFONY_VERSION" != "" ]; then composer require "symfony/symfony:${SYMFONY_VERSION}" --no-update; fi;
