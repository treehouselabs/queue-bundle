<?php

namespace TreeHouse\QueueBundle;

final class QueueEvents
{
    /**
     * Dispatched when the consumer needs to flush. This typically occurs after a
     * batch completes during the consume command, or when a single message is processed.
     */
    const FLUSH = 'queue.flush';
}
