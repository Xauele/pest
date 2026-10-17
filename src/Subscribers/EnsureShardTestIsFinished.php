<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\RunIntegrity;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * @internal
 */
final readonly class EnsureShardTestIsFinished implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            RunIntegrity::observe($test->className());
        }
    }
}
