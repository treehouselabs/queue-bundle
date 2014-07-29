<?php

namespace TreeHouse\QueueBundle\Command;

use Doctrine\ORM\AbstractQuery;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\Queue\Message\Publisher\MessagePublisherInterface;

class QueuePublishCommand extends ContainerAwareCommand
{
    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('queue:publish');
        $this->addArgument('queue', InputArgument::REQUIRED, 'The name of the queue to publish to');
        $this->addArgument('payload', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The message payload');
        $this->addOption('dql', 'd', InputOption::VALUE_OPTIONAL, 'A DQL query to execute. The resulting entities will all be published. This does not work when also given a payload');
        $this->setDescription('Publishes a message to a queue. Optionally a query can be run for which the result set is published');
        $this->setHelp(<<<HELP
This utility command publishes messages to a specified queue. You can publish a single message by giving the queue name
and the payload:

    <comment>php app/console %command.name% mail.send "recipient@example.org" "This is a test subject" "This is the body"</comment>

Or you can perform a Doctrine query where the entiry result set is published:

    <comment>php app/console %command.name% article.publish -d "SELECT a FROM AcmeBlogBundle:Article a WHERE a.published = 0"</comment>

HELP
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name    = $input->getArgument('queue');
        $payload = $input->getArgument('payload');
        $dql     = $input->getOption('dql');

        if ($payload && $dql) {
            throw new \InvalidArgumentException('You cannot provide both a <comment>payload</comment> and a <comment>dql</comment> query.');
        }

        $publisher = $this->getMessagePublisher($name);

        if ($payload) {
            $message = $publisher->createMessage($payload);
            $publisher->publish($message);

            $output->writeln(sprintf('Published message for payload <info>%s</info>', json_encode($payload)));

            return 0;
        }

        if ($dql) {
            $manager = $this->getContainer()->get('doctrine')->getManager();

            /** @var AbstractQuery $query */
            $query = $manager->createQuery($dql);
            foreach ($query->iterate() as list ($entity)) {
                $message = $publisher->createMessage($entity);
                $publisher->publish($message);

                $output->writeln(sprintf('Published message for entity <info>%s</info>', $this->entityToString($entity)));
                $manager->detach($entity);
            }

            return 0;
        }

        throw new \InvalidArgumentException('Specify either a <comment>payload</comment> or a <comment>dql</comment> query.');
    }

    /**
     * @param string $name
     *
     * @return MessagePublisherInterface
     */
    protected function getMessagePublisher($name)
    {
        return $this->getContainer()->get(sprintf('tree_house.queue.publisher.%s', $name));
    }

    /**
     * @param object $entity
     *
     * @return string
     */
    protected function entityToString($entity)
    {
        $class = get_class($entity);
        $meta = $this->getContainer()->get('doctrine')->getManagerForClass($class)->getClassMetadata($class);

        $id = $meta->getIdentifierValues($entity);
        $title = method_exists($entity, '__toString') ? (string) $entity : get_class($entity) . '@' . spl_object_hash($entity);

        return sprintf('%s %s', json_encode($id), $title);
    }
}
