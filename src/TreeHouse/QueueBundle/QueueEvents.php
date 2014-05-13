<?php

namespace TreeHouse\QueueBundle;

final class QueueEvents
{
    /**
     * Dispatched when the worker needs to flush. This typically occurs after a
     * batch completes during the run command, or when a single job is finished.
     */
    const FLUSH        = 'queue.flush';

    /**
     * Dispatched when the run command terminates
     */
    const RUN_TERMINATE = 'queue.run.terminate';
}
