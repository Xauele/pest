<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Tia;
use Pest\Plugins\Tia\Contracts\State;
use Pest\Plugins\Tia\CoverageTestCatalog;
use Pest\Support\Container;
use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;

/**
 * @internal
 */
final readonly class EnsureTiaCoverageCatalogIsCaptured implements LoadedSubscriber
{
    public function __construct(private CoverageTestCatalog $catalog) {}

    public function notify(Loaded $event): void
    {
        $state = Container::getInstance()->get(State::class);
        assert($state instanceof State);

        if (! $state->exists(Tia::KEY_COVERAGE_MARKER)) {
            return;
        }

        $this->catalog->capture($event);
    }
}
