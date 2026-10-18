<?php

declare(strict_types=1);

if (! extension_loaded('pest_observer')) {
    fwrite(STDERR, "Native Zend observer was not loaded.\n");
    exit(1);
}

$root = realpath(__DIR__.'/..');
if (! is_string($root)) {
    throw new RuntimeException('Cannot canonicalize project root');
}

function nativeObservedSource(): int
{
    return 42;
}

final class NativeObservedClass
{
    public static function execute(): int
    {
        return nativeObservedSource();
    }
}

$check = static function (bool $condition, string $why): void {
    if (! $condition) {
        throw new RuntimeException($why);
    }
};

$check(pest_observer_start($root), 'First scope refused');
$check(! pest_observer_start($root), 'Nested scope must fail closed');
$check(NativeObservedClass::execute() === 42, 'Fixture execution changed');
$first = pest_observer_stop();
$check(in_array(__FILE__, $first['files'], true), 'Zend method entry file was not recorded');
$check($first['safe_to_skip_tests'] === false, 'Observer must never prove safe omission');
$check($first['dynamic_code'] === false, 'Ordinary method unexpectedly marked dynamic');
$check($first['scope'] === 'function-entry-positive-evidence', 'Unrecognized proof domain');
echo "NATIVE_FUNCTION_ENTRY_CAPTURE=PASS\n";

if (! pest_observer_start($root)) {
    throw new RuntimeException('Could not restart the observer scope');
}
$empty = pest_observer_stop();
$check($empty['files'] === [], 'Evidence from a previous session leaked');
echo "NATIVE_PER_TEST_RESET=PASS\n";

if (! pest_observer_start($root)) {
    throw new RuntimeException('Could not start eval scope');
}
eval('function specialNativeEvalFunction(): int { return 7; }');
$check(specialNativeEvalFunction() === 7, 'Eval code failed');
$dynamic = pest_observer_stop();
$check($dynamic['dynamic_code'] === true, 'Dynamic source was not detected');
$check($dynamic['safe_to_skip_tests'] === false, 'Unsafe dynamic run claims proof of omission');
echo "NATIVE_EVAL_FAIL_CLOSED=PASS\n";

$check(! pest_observer_start('relative/root'), 'Relative project root was accepted');
$check(! pest_observer_start($root.'/..'), 'Noncanonical project root was accepted');
$check(! pest_observer_start(__FILE__), 'A regular file was accepted as project root');
try {
    pest_observer_stop();
    throw new RuntimeException('Unbalanced stop unexpectedly succeeded');
} catch (Error $exception) {
    $check(str_contains($exception->getMessage(), 'active recording scope'), 'Unexpected end error');
}
echo "NATIVE_INVALID_SCOPE_REJECTED=PASS\n";

$fixture = __DIR__.'/__pest_native_probe_'.bin2hex(random_bytes(4)).'.php';
file_put_contents($fixture, "<?php\nfunction nativeProbeInclude(): int { return 11; }\n");
try {
    if (! pest_observer_start($root)) {
        throw new RuntimeException('Failed to start include scope');
    }
    require $fixture;
    $check(nativeProbeInclude() === 11, 'Included PHP code failed');
    $files = pest_observer_stop()['files'];
    $check(in_array($fixture, $files, true), 'Top-level include/call source file was not observed');
    $check(count($files) === count(array_unique($files)), 'Duplicate file evidence');
    echo "NATIVE_INCLUDES_AND_DEDUPLICATION=PASS\n";
} finally {
    @unlink($fixture);
}

// Internal file reads have no user-function entry to observe.
// This negative proof must never be used to authorize skipping tests.
$dataFile = tempnam(sys_get_temp_dir(), 'pest-native-io-');
if (! is_string($dataFile)) {
    throw new RuntimeException('Could not allocate native I/O negative fixture');
}

try {
    file_put_contents($dataFile, '{"permission":"admin"}');

    if (! pest_observer_start($root)) {
        throw new RuntimeException('Failed to start data-file blind-spot probe');
    }

    $value = file_get_contents($dataFile);
    $observations = pest_observer_stop();

    $check($value === '{"permission":"admin"}', 'Data fixture read failed');
    $check(! in_array($dataFile, $observations['files'], true), 'Internal file input unexpectedly claimed by function-entry probe');
    $check($observations['safe_to_skip_tests'] === false, 'Native blind spot falsely authorized skipping');
    echo "NATIVE_DATA_DEPENDENCY_BLIND_SPOT_FAIL_CLOSED=PASS\n";
} finally {
    @unlink($dataFile);
}
