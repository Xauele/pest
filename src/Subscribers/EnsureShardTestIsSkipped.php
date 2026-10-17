<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\RunIntegrity;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * @internal
 */
final readonly class EnsureShardTestIsSkipped implements SkippedSubscriber
{
    public function notify(Skipped $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            RunIntegrity::observe($test->className());
        }
    }
}
