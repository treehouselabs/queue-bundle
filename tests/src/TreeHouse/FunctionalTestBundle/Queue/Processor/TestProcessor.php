<?php

namespace TreeHouse\FunctionalTestBundle\Queue\Processor;

use TreeHouse\Queue\Message\Message;
use TreeHouse\Queue\Processor\ProcessorInterface;

class TestProcessor implements ProcessorInterface
{
    /**
     * @inheritdoc
     */
    public function process(Message $message)
    {
        // noop
    }
}
