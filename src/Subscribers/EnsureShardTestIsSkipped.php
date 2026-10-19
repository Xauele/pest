<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\RunIntegrity;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * @internal
 */
final readonly class EnsureShardTestIsSkipped implements SkippedSubscriber
{
    public function notify(Skipped $event): void
    {
        RunIntegrity::observeSkipped($event->test()->id());
    }
}
