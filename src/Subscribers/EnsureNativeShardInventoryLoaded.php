<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Shard\NativeShardInventory;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;

/** @internal */
final readonly class EnsureNativeShardInventoryLoaded implements LoadedSubscriber
{
    public function notify(Loaded $event): void
    {
        NativeShardInventory::capture($event->testSuite()->tests()->asArray());
    }
}
