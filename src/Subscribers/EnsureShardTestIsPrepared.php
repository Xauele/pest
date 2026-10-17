<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\RunIntegrity;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * @internal
 */
final readonly class EnsureShardTestIsPrepared implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            RunIntegrity::observe($test->className());
        }
    }
}
