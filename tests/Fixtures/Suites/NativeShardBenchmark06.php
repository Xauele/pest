<?php

declare(strict_types=1);

it('completes deterministic native shard benchmark file 06', function (int $seed): void {
    $checksum = 0;

    for ($iteration = 0; $iteration < 30; $iteration++) {
        $checksum += (($iteration + 1) * $seed) % 97;
    }

    expect($checksum)->toBeGreaterThanOrEqual(0)->toBeLessThan(2910);
})->with(range(1, 20));
