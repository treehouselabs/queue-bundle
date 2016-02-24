<?php

namespace TreeHouse\QueueBundle;

/**
 * @codeCoverageIgnore
 */
final class QueueEvents
{
    /**
     * Dispatched when the consumer needs to flush. This typically occurs after a
     * batch completes during the consume command, or when a single message is processed.
     */
    const FLUSH = 'queue.flush';

    /**
     * Dispatched before a message is processed
     */
    const PRE_CONSUME = 'queue.consume.pre';

    /**
     * Dispatched after a message is successfully processed
     */
    const POST_CONSUME = 'queue.consume.post';
}
