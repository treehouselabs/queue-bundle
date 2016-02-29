<?php

namespace TreeHouse\QueueBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use TreeHouse\Queue\Amqp\QueueInterface;

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

        $name = $input->getArgument('queue');
        $queue = $this->getQueue($name);
        $verbose = $output->getVerbosity() > $output::VERBOSITY_VERBOSE;

        $output->writeln(sprintf('Purging queue <info>%s</info>', $name));

        $purged = 0;
        while ($envelope = $queue->get()) {
            ++$purged;

            if ($verbose) {
                $output->writeln(sprintf('<option=bold;fg=red>- %s</option=bold;fg=red>', $envelope->getDeliveryTag()));
            } else {
                $output->write(str_pad(sprintf('Purged <info>%d</info> messages', $purged), 50, ' ', STR_PAD_RIGHT));
                $output->write("\x0D");
            }
        }

        $output->writeln(str_pad(sprintf('Purged <info>%d</info> messages', $purged), 50, ' ', STR_PAD_RIGHT));

        return 0;
    }

    /**
     * @param string $name
     *
     * @return QueueInterface
     */
    protected function getQueue($name)
    {
        return $this->getContainer()->get(sprintf('tree_house.queue.queue.%s', $name));
    }
}
