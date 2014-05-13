<?php

use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use PHPUnit_Framework_Assert as Assert;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use TreeHouse\Queue\Message\Message;
use TreeHouse\Queue\Message\Publisher\MessagePublisherInterface;
use TreeHouse\Queue\Processor\RetryProcessor;
use TreeHouse\QueueBundle\DependencyInjection\TreeHouseQueueExtension;

/**
 * Behat context class.
 */
class FeatureContext implements SnippetAcceptingContext
{
    /**
     * @var string
     */
    private $config;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var \AMQPExchange[]
     */
    private $exchanges;

    /**
     * @var \AMQPQueue[]
     */
    private $queues;

    /**
     * @var MessagePublisherInterface
     */
    private $publisher;

    /**
     * @var Message
     */
    private $message;

    /**
     * @var boolean
     */
    private $published;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->published = false;
    }

    /**
     * @BeforeSuite
     */
    public static function setup(BeforeSuiteScope $scope)
    {
        require_once __DIR__ . '/../../tests/bootstrap.php';
    }

    /**
     * @BeforeScenario
     */
    public function beforeScenario()
    {
        $this->exchanges = [];
        $this->queues    = [];
    }

    /**
     * Clears all the exchanges, queues, etc.
     *
     * @AfterScenario
     */
    public function purgeBroker()
    {
        foreach ($this->exchanges as $exchange) {
            $exchange->delete();
        }

        foreach ($this->queues as $queue) {
            $queue->purge();
            $queue->delete();
        }
    }

    /**
     * @Given the config:
     */
    public function theConfig(PyStringNode $config)
    {
        $this->config = $config;
    }

    /**
     * @Given a message publisher for :name
     */
    public function aMessagePublisherFor($name)
    {
        $this->publisher = $this->container->get(sprintf('tree_house.queue.publisher.%s', $name));
    }

    /**
     * @When I build the container
     */
    public function iBuildTheContainer()
    {
        $this->buildContainer();
    }

    /**
     * @When I create a message with payload :payload
     */
    public function iCreateAMessageWithBody($payload)
    {
        $this->message = $this->publisher->createMessage($payload);
    }

    /**
     * @When I publish a message with payload :payload to the :xchg exchange
     */
    public function iPublishAMessageWithBodyToTheExchange($payload, $xchg)
    {
        $this->message = $this->publisher->createMessage($payload);
        $this->published = $this->publisher->publish($this->message, $xchg);
    }

    /**
     * @Then I should have a connection named :name
     */
    public function iShouldHaveAConnectionNamed($name)
    {
        $id = sprintf('tree_house.queue.connection.%s', $name);
        Assert::assertTrue($this->container->has($id));

        $connection = $this->container->get($id);
        Assert::assertInstanceOf($this->container->getParameter('tree_house.queue.connection.class'), $connection);
    }

    /**
     * @Then the :name connection should have host :host
     */
    public function theNamedConnectionShouldHaveHost($name, $host)
    {
        /** @var AMQPConnection $conn */
        $conn = $this->container->get(sprintf('tree_house.queue.connection.%s', $name));
        Assert::assertEquals($host, $conn->getHost());
    }

    /**
     * @Then the :name connection should have port :port
     */
    public function theConnectionShouldHavePort($name, $port)
    {
        /** @var AMQPConnection $conn */
        $conn = $this->container->get(sprintf('tree_house.queue.connection.%s', $name));
        Assert::assertEquals($port, $conn->getPort());
    }

    /**
     * @Then a message should be returned
     */
    public function aMessageShouldBeReturned()
    {
        Assert::assertNotNull($this->message);
        Assert::assertInstanceOf(Message::class, $this->message);
    }

    /**
     * @Then the message should have the body :body
     */
    public function theMessageShouldHaveTheBody($body)
    {
        Assert::assertSame($body, $this->message->getBody());
    }

    /**
     * @Then the message should be published
     */
    public function theMessageShouldBePublished()
    {
        Assert::assertTrue($this->published);
    }

    /**
     * @Then I should have a publisher named :name
     */
    public function iShouldHaveAPublisherNamed($name)
    {
        $id = sprintf('tree_house.queue.publisher.%s', $name);
        Assert::assertTrue($this->container->has($id));

        $publisher = $this->container->get($id);
        Assert::assertInstanceOf($this->container->getParameter('tree_house.queue.publisher.class'), $publisher);
    }

    /**
     * @Then the :name publisher should serialize using :type
     */
    public function thePublisherShouldSerializeUsingPhp($name, $type)
    {
        $id = sprintf('tree_house.queue.serializer.%s', $name);
        Assert::assertTrue($this->container->has($id));

        $serializer = $this->container->get($id);
        Assert::assertInstanceOf(
            $this->container->getParameter(sprintf('tree_house.queue.serializer.%s.class', $type)),
            $serializer
        );
    }

    /**
     * @Then I should have an exchange named :name
     */
    public function iShouldHaveAnExchangeNamed($name)
    {
        $id = sprintf('tree_house.queue.exchange.%s', $name);
        Assert::assertTrue($this->container->has($id));

        $exchange = $this->getExchange($name);
        Assert::assertInstanceOf($this->container->getParameter('tree_house.queue.exchange.class'), $exchange);
    }

    /**
     * @Then the :name exchange should be of type :type
     */
    public function theExchangeShouldBeOfType($name, $type)
    {
        $exchange = $this->getExchange($name);
        Assert::assertEquals($type, $exchange->getType());
    }

    /**
     * @Then the :name exchange should be durable
     */
    public function theExchangeShouldBeDurable($name)
    {
        $this->theExchangeShouldHaveFlag($name, AMQP_DURABLE);
    }

    /**
     * @Then the :name exchange should not be durable
     */
    public function theExchangeShouldNotBeDurable($name)
    {
        $this->theExchangeShouldNotHaveFlag($name, AMQP_DURABLE);
    }

    /**
     * @Then the :name exchange should have flag :flag
     */
    public function theExchangeShouldHaveFlag($name, $flag)
    {
        $exchange = $this->getExchange($name);
        Assert::assertGreaterThan(0, $exchange->getFlags() & $flag);
    }

    /**
     * @Then the :name exchange should not have flag :flag
     */
    public function theExchangeShouldNotHaveFlag($name, $flag)
    {
        $exchange = $this->getExchange($name);
        Assert::assertSame(0, $exchange->getFlags() & $flag);
    }

    /**
     * @Then I should have a consumer named :name
     */
    public function iShouldHaveAConsumerNamed($name)
    {
        $id = sprintf('tree_house.queue.consumer.%s', $name);
        Assert::assertTrue($this->container->has($id));

        $consumer = $this->container->get($id);
        Assert::assertInstanceOf($this->container->getParameter('tree_house.queue.consumer.class'), $consumer);
    }

    /**
     * @Then the :name consumer should process using the :class class
     */
    public function theConsumerShouldProcessUsingTheClass($name, $class)
    {
        $processor = $this->container->get(sprintf('tree_house.queue.processor.%s', $name));

        if ($processor instanceof RetryProcessor) {
            $processor = $processor->getProcessor();
        }

        Assert::assertInstanceOf($class, $processor);
    }

    /**
     * @Then the :name consumer should process using the :id service
     */
    public function theConsumerShouldProcessUsingTheService($name, $serviceId)
    {
        $processorId = sprintf('tree_house.queue.processor.%s', $name);
        Assert::assertTrue($this->container->has($processorId));
        Assert::assertTrue($this->container->has($serviceId));
        Assert::assertSame($this->container->get($serviceId), $this->container->get($processorId));
    }

    /**
     * @Then the :name consumer should get :attempts attempt
     * @Then the :name consumer should get :attempts attempts
     */
    public function theConsumerShouldGetAttempts($name, $attempts)
    {
        $id = sprintf('tree_house.queue.processor.%s', $name);
        $processor = $this->container->get($id);

        /** @var RetryProcessor $processor */
        Assert::assertInstanceOf(RetryProcessor::class, $processor);
        Assert::assertEquals($attempts, $processor->getMaxAttempts());
    }

    /**
     * @Then I should have a queue named :name
     */
    public function iShouldHaveAQueueNamed($name)
    {
        $id = sprintf('tree_house.queue.queue.%s', $name);
        Assert::assertTrue($this->container->has($id));

        $queue = $this->getQueue($name);
        Assert::assertInstanceOf($this->container->getParameter('tree_house.queue.queue.class'), $queue);
    }

    /**
     * @Then the :name queue should be durable
     */
    public function theQueueShouldBeDurable($name)
    {
        $this->theQueueShouldHaveFlag($name, AMQP_DURABLE);
    }

    /**
     * @Then the :name queue should not be durable
     */
    public function theQueueShouldNotBeDurable($name)
    {
        $this->theQueueShouldNotHaveFlag($name, AMQP_DURABLE);
    }

    /**
     * @Then the :name queue should be passive
     */
    public function theQueueShouldBePassive($name)
    {
        $this->theQueueShouldHaveFlag($name, AMQP_PASSIVE);
    }

    /**
     * @Then the :name queue should not be passive
     */
    public function theQueueShouldNotBePassive($name)
    {
        $this->theQueueShouldNotHaveFlag($name, AMQP_PASSIVE);
    }

    /**
     * @Then the :name queue should be exclusive
     */
    public function theQueueShouldBeExclusive($name)
    {
        $this->theQueueShouldHaveFlag($name, AMQP_EXCLUSIVE);
    }

    /**
     * @Then the :name queue should not be exclusive
     */
    public function theQueueShouldNotBeExclusive($name)
    {
        $this->theQueueShouldHaveFlag($name, AMQP_EXCLUSIVE);
    }

    /**
     * @Then the :name queue should auto-delete
     */
    public function theQueueShouldAutoDelete($name)
    {
        $this->theQueueShouldHaveFlag($name, AMQP_AUTODELETE);
    }

    /**
     * @Then the :name queue should not auto-delete
     */
    public function theQueueShouldNotAutoDelete($name)
    {
        $this->theQueueShouldNotHaveFlag($name, AMQP_AUTODELETE);
    }

    /**
     * @Then the :name queue should have flag :flag
     */
    public function theQueueShouldHaveFlag($name, $flag)
    {
        $queue = $this->getQueue($name);
        Assert::assertGreaterThan(0, $queue->getFlags() & $flag);
    }

    /**
     * @Then the :name queue should not have flag :flag
     */
    public function theQueueShouldNotHaveFlag($name, $flag)
    {
        $queue = $this->getQueue($name);
        Assert::assertSame(0, $queue->getFlags() & $flag);
    }

    /**
     * @param string $name
     *
     * @return \AMQPExchange
     */
    protected function getExchange($name)
    {
        if (!array_key_exists($name, $this->exchanges)) {
            $this->exchanges[$name] = $this->container->get(sprintf('tree_house.queue.exchange.%s', $name));
        }

        return $this->exchanges[$name];
    }

    /**
     * @param string $name
     *
     * @return \AMQPQueue
     */
    protected function getQueue($name)
    {
        if (!array_key_exists($name, $this->queues)) {
            $this->queues[$name] = $this->container->get(sprintf('tree_house.queue.queue.%s', $name));
        }

        return $this->queues[$name];
    }

    /**
     * @throws \RuntimeException
     *
     * @return ContainerBuilder
     */
    private function buildContainer()
    {
        // write the config to a tmp file
        if ((false === $file = tempnam(sys_get_temp_dir(), 'config')) || (false === file_put_contents($file, $this->config))) {
            throw new \RuntimeException('Could not write config to a temp file');
        }

        $container = new ContainerBuilder(new ParameterBag(['kernel.debug' => true]));
        $container->registerExtension(new TreeHouseQueueExtension());

        $locator = new FileLocator(dirname($file));
        $loader = new YamlFileLoader($container, $locator);
        $loader->load($file);

        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        return $this->container = $container;
    }
}
