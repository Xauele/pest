<?php

declare(strict_types=1);

use Pest\Plugins\Shard\NativeShardInventory;

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

        expect($seen)->toHaveCount(count($weights))
            ->and(array_diff_key($weights, $seen))->toBe([]);
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
