<?php

declare(strict_types=1);

$mode = getenv('PEST_OBSERVER_BENCH_MODE') ?: 'absent';
if (! in_array($mode, ['absent', 'idle', 'active'], true)) {
    throw new RuntimeException('Unknown benchmark mode');
}
if ($mode !== 'absent' && ! extension_loaded('pest_observer')) {
    throw new RuntimeException('Extension required for observer benchmark');
}
if ($mode === 'absent' && extension_loaded('pest_observer')) {
    throw new RuntimeException('No-extension baseline accidentally loaded observer');
}

function nativeBenchmarkLeaf(int $value): int
{
    return $value + 1;
}

function nativeBenchmarkWorkload(int $iterations): int
{
    $total = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $total += nativeBenchmarkLeaf($i);
    }

    return $total;
}

$iterations = 500000;
nativeBenchmarkWorkload(10000);
if ($mode === 'active' && ! pest_observer_start(realpath(__DIR__.'/..'))) {
    throw new RuntimeException('Cannot activate observer');
}

$start = hrtime(true);
$total = nativeBenchmarkWorkload($iterations);
$seconds = (hrtime(true) - $start) / 1000000000;

if ($mode === 'active') {
    $result = pest_observer_stop();
    if (! in_array(__FILE__, $result['files'], true) || $result['safe_to_skip_tests']) {
        throw new RuntimeException('Native observer evidence inconsistent');
    }
}
if ($total !== intdiv($iterations * ($iterations + 1), 2)) {
    throw new RuntimeException('Benchmark result corrupted');
}

printf("NATIVE_FCALL_BENCH mode=%s seconds=%.6f calls=%d\n", $mode, $seconds, $iterations);
