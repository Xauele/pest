<?php

declare(strict_types=1);

it('accepts both dataset rows', function (int $value): void {
    expect($value)->toBeGreaterThan(0);
})->with([
    'first' => [1],
    'second' => [2],
]);
