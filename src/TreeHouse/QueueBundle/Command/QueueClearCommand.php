<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use TreeHouse\Queue\Message\Message;
use TreeHouse\Queue\Message\Provider\MessageProviderInterface;

class QueueClearCommand extends ContainerAwareCommand
{
    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('queue:clear');
        $this->addArgument('queue', InputArgument::REQUIRED, 'The name of the queue to purge');
        $this->setDescription('Purges all messages in a queue');
        $this->setHelp(<<<HELP
This utility command purges messages from a specified queue, by acknowledging
all messages. Note that this does not purge messages that have been retrieved
by consumers, but are unacknowledged (neither acked nor nacked). These messages
can be put back onto the queue be closing all connections (or restarting the
broker as a last resort).

<error>WARNING: this is a destructive operation and cannot be undone!</error>
HELP
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->isInteractive()) {
            $question = new ConfirmationQuestion(
                '<question>CAREFUL: this is a destructive operation, which cannot be undone! Are you sure you want to proceed? [n]</question> ',
                false
            );

            if (!(new QuestionHelper())->ask($input, $output, $question)) {
                return 0;
            }
        }

        $name     = $input->getArgument('queue');
        $provider = $this->getMessageProvider($name);

        $callback = null;
        if ($output->getVerbosity() > $output::VERBOSITY_VERBOSE) {
            $callback = function (OutputInterface $output, Message $message) {
                $output->writeln(sprintf('<option=bold;fg=red>- %s</>', $message->getId()));
            };
        }

        $output->writeln(sprintf('Purging queue <info>%s</info>', $name));

        while ($message = $provider->get()) {
            if ($callback) {
                $callback($output, $message);
            }

            $provider->ack($message);
        }

        $output->writeln(sprintf('Purged queue <info>%s</info>', $name));
    }

    /**
     * @param string $name
     *
     * @return MessageProviderInterface
     */
    protected function getMessageProvider($name)
    {
        return $this->getContainer()->get(sprintf('tree_house.queue.provider.%s', $name));
    }
}
