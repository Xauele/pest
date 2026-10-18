<?php

declare(strict_types=1);

use Pest\Plugins\Shard\NativeShardInventory;

it('preserves stable discovery-order allocation without assuming test counts predict duration', function (): void {
    $files = ['/suite/a.php', '/suite/b.php', '/suite/c.php', '/suite/d.php'];

    expect(NativeShardInventory::allocateInDiscoveryOrder($files, 1, 2))->toBe(['/suite/a.php', '/suite/c.php'])
        ->and(NativeShardInventory::allocateInDiscoveryOrder($files, 2, 2))->toBe(['/suite/b.php', '/suite/d.php'])
        ->and(NativeShardInventory::allocateInDiscoveryOrder($files, 5, 8))->toBeEmpty()
        ->and(fn () => NativeShardInventory::allocateInDiscoveryOrder($files, 0, 2))->toThrow(RuntimeException::class);
});

it('uses measured durations to split expensive files rather than counting cases', function (): void {
    $weights = [
        '/suite/a.php' => 9.5,
        '/suite/b.php' => 0.1,
        '/suite/c.php' => 9.0,
        '/suite/d.php' => 0.2,
    ];

    expect(NativeShardInventory::allocateByEstimatedDuration($weights, 1, 2))->toBe(['/suite/a.php', '/suite/d.php'])
        ->and(NativeShardInventory::allocateByEstimatedDuration($weights, 2, 2))->toBe(['/suite/c.php', '/suite/b.php']);
});

it('assigns every estimated-duration file once and is invariant to insertion order', function (): void {
    $weights = [];

    for ($i = 0; $i < 75; $i++) {
        $weights[sprintf('/suite/%03d.php', $i)] = 0.1 + (($i * 17) % 19) / 10.0;
    }

    foreach ([1, 2, 4, 16, 90] as $total) {
        $seen = [];

        for ($shard = 1; $shard <= $total; $shard++) {
            $selected = NativeShardInventory::allocateByEstimatedDuration($weights, $shard, $total);

            expect($selected)->toBe(NativeShardInventory::allocateByEstimatedDuration(array_reverse($weights, true), $shard, $total));

            foreach ($selected as $file) {
                expect(isset($seen[$file]))->toBeFalse();
                $seen[$file] = true;
            }
        }

        expect($seen)->toHaveSameSize($weights);
    }
});

it('rejects corrupt duration weights without silently producing a green plan', function (): void {
    expect(fn () => NativeShardInventory::allocateByEstimatedDuration(['/suite/x.php' => -1.0], 1, 2))->toThrow(RuntimeException::class)
        ->and(fn () => NativeShardInventory::allocateByEstimatedDuration(['/suite/x.php' => INF], 1, 2))->toThrow(RuntimeException::class)
        ->and(fn () => NativeShardInventory::allocateByEstimatedDuration(['/suite/x.php' => 0.0], 1, 2))->toThrow(RuntimeException::class);
});

it('allocates whole files by discovered test counts rather than file order', function (): void {
    $weights = [
        '/suite/c.php' => 1,
        '/suite/a.php' => 100,
        '/suite/d.php' => 1,
        '/suite/b.php' => 1,
    ];

    expect(NativeShardInventory::allocateByTestCount($weights, 1, 2))->toBe(['/suite/a.php'])
        ->and(NativeShardInventory::allocateByTestCount($weights, 2, 2))->toBe(['/suite/b.php', '/suite/c.php', '/suite/d.php']);
});

it('reproducibly assigns each file once across shard counts including empty shards', function (): void {
    $weights = [];

    for ($i = 0; $i < 53; $i++) {
        $weights[sprintf('/suite/%03d.php', $i)] = ($i % 7) + 1;
    }

    foreach ([1, 2, 4, 16, 128] as $total) {
        $seen = [];

        for ($index = 1; $index <= $total; $index++) {
            $selected = NativeShardInventory::allocateByTestCount($weights, $index, $total);

            expect($selected)->toBe(NativeShardInventory::allocateByTestCount(array_reverse($weights, true), $index, $total));

            foreach ($selected as $filename) {
                expect(isset($seen[$filename]))->toBeFalse();
                $seen[$filename] = true;
            }
        }

        expect($seen)->toHaveSameSize($weights)
            ->and(array_diff_key($weights, $seen))->toBeEmpty();
    }
});

it('uses a lexical file-name tie break for equal test counts', function (): void {
    $weights = ['/suite/z.php' => 1, '/suite/a.php' => 1, '/suite/b.php' => 1];

    expect(NativeShardInventory::allocateByTestCount($weights, 1, 2))->toBe(['/suite/a.php', '/suite/z.php'])
        ->and(NativeShardInventory::allocateByTestCount($weights, 2, 2))->toBe(['/suite/b.php']);
});

it('rejects invalid shard coordinates and invalid native discovery weights', function (): void {
    expect(fn () => NativeShardInventory::allocateByTestCount(['/suite/a.php' => 1], 0, 2))->toThrow(RuntimeException::class)
        ->and(fn () => NativeShardInventory::allocateByTestCount(['/suite/a.php' => 1], 3, 2))->toThrow(RuntimeException::class)
        ->and(fn () => NativeShardInventory::allocateByTestCount(['/suite/a.php' => 0], 1, 2))->toThrow(RuntimeException::class);
});
