<?php

declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];
$isDiscovery = in_array('--list-tests', $argv, true) || in_array('--list-test-ids', $argv, true);

it('must not certify a silently lost dataset row', function (int $value): void {
    expect($value)->toBeGreaterThan(0);
})->with($isDiscovery ? [
    'first' => [1],
    'second' => [2],
] : [
    'first' => [1],
]);
