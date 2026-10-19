<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\RunIntegrity;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * @internal
 */
final readonly class EnsureShardTestIsFinished implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        RunIntegrity::observeFinished($event->test()->id());
    }
}
