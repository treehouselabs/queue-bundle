<?php

namespace TreeHouse\QueueBundle\CacheWarmer;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class AmqpCacheWarmer implements CacheWarmerInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @inheritdoc
     */
    public function isOptional()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function warmUp($cacheDir)
    {
        $this->loadServices($this->container->getParameter('tree_house.queue.exchanges'));
        $this->loadServices($this->container->getParameter('tree_house.queue.queues'));
    }

    /**
     * Loads the services from the DIC, thereby automatically declaring associated exchanges/queues.
     *
     * @param string[] $services
     */
    private function loadServices($services)
    {
        foreach ($services as $service) {
            if (false === $service['auto_declare']) {
                continue;
            }

            $this->container->get($service['id']);
        }
    }
}
