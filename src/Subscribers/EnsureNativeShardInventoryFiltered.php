<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\NativeShardInventory;
use PHPUnit\Event\TestSuite\Filtered;
use PHPUnit\Event\TestSuite\FilteredSubscriber;

/** @internal */
final readonly class EnsureNativeShardInventoryFiltered implements FilteredSubscriber
{
    public function notify(Filtered $event): void
    {
        NativeShardInventory::capture($event->testSuite()->tests()->asArray());
    }
}
