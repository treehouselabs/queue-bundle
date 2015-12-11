<?php

use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\PyStringNode;
use PHPUnit_Framework_Assert as Assert;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use TreeHouse\Queue\Message\Message;
use TreeHouse\Queue\Message\Publisher\MessagePublisherInterface;
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
    public static function setup()
    {
        require_once __DIR__.'/../../tests/bootstrap.php';
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
     *
     * @param PyStringNode $config
     */
    public function theConfig(PyStringNode $config)
    {
        $this->config = $config;
    }

    /**
     * @Given a message publisher for :name
     *
     * @param string $name
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
     *
     * @param string $payload
     */
    public function iCreateAMessageWithBody($payload)
    {
        $this->message = $this->publisher->createMessage($payload);
    }

    /**
     * @When I publish a message with payload :payload
     *
     * @param string $payload
     */
    public function iPublishAMessageWithPayload($payload)
    {
        $this->message = $this->publisher->createMessage($payload);
        $this->published = $this->publisher->publish($this->message);
    }

    /**
     * @When I publish a message with payload :payload to the :xchg exchange
     *
     * @param string $payload
     * @param string $xchg
     */
    public function iPublishAMessageWithPayloadToTheExchange($payload, $xchg)
    {
        $this->aMessagePublisherFor($xchg);
        $this->iPublishAMessageWithPayload($payload);
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
     *
     * @param string $body
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
