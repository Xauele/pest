<?php

declare(strict_types=1);

namespace Pest\Plugins;

use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;
use Pest\Exceptions\InvalidOption;
use Pest\Plugins\Shard\RunIntegrity;
use Pest\Subscribers\EnsureShardTestIsFinished;
use Pest\Subscribers\EnsureShardTestIsSkipped;
use Pest\Subscribers\EnsureShardTimingFinished;
use Pest\Subscribers\EnsureShardTimingsAreCollected;
use Pest\Subscribers\EnsureShardTimingStarted;
use Pest\TestSuite;
use PHPUnit\Event;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class Shard implements AddsOutput, HandlesArguments, Terminable
{
    use Concerns\HandleArguments;

    private const string SHARD_OPTION = 'shard';

    private const int MAX_FILTER_LENGTH = 32768;

    private const string CLASS_END = "\0";

    private const string INTEGRITY_GLOBAL = 'SHARD_INTEGRITY_RUN_ID';

    /**
     * @var array{
     *     index: int,
     *     total: int,
     *     testsRan: int,
     *     testsCount: int
     * }|null
     */
    private static ?array $shard = null;

    private static bool $updateShards = false;

    private static bool $timeBalanced = false;

    private static bool $shardsOutdated = false;

    private static bool $passed = false;

    /** @var array<string, true>|null */
    private static ?array $expectedTestIds = null;

    /** @var array<string, array<string, true>> */
    private array $discoveredTestIds = [];

    private static ?string $integrityRunId = null;

    /**
     * @var array<string, float>|null
     */
    private static ?array $collectedTimings = null;

    /**
     * @var list<string>|null
     */
    private static ?array $knownTests = null;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function handleArguments(array $arguments): array
    {
        if (Parallel::isWorker()) {
            $runId = Parallel::getGlobal(self::INTEGRITY_GLOBAL);

            if (is_string($runId)) {
                self::$integrityRunId = $runId;
                $this->registerIntegritySubscribers();
            }
        }

        if ($this->hasArgument('--update-shards', $arguments)) {
            return $this->handleUpdateShards($arguments);
        }

        if (Parallel::isWorker() && Parallel::getGlobal('UPDATE_SHARDS') === true) {
            self::$updateShards = true;

            Event\Facade::instance()->registerSubscriber(new EnsureShardTimingStarted);
            Event\Facade::instance()->registerSubscriber(new EnsureShardTimingFinished);

            return $arguments;
        }

        if (! $this->hasArgument('--shard', $arguments)) {
            return $arguments;
        }

        // @phpstan-ignore-next-line
        $input = new ArgvInput($arguments);

        ['index' => $index, 'total' => $total] = self::getShard($input);

        $arguments = $this->popArgument("--shard=$index/$total", $this->popArgument('--shard', $this->popArgument(
            "$index/$total",
            $arguments,
        )));

        /** @phpstan-ignore-next-line */
        $tests = $this->allTests($arguments);

        if ($tests === []) {
            throw new InvalidOption('No test classes were discovered for sharding. Check [--list-tests] before running CI shards.');
        }

        $timings = $this->loadShardsFile();
        if ($timings !== null) {
            $knownTests = array_values(array_filter($tests, fn (string $test): bool => isset($timings[$test])));
            $newTests = array_values(array_diff($tests, $knownTests));

            $partitions = $this->partitionByTime($knownTests, $timings, $total);

            foreach ($newTests as $i => $test) {
                $partitions[$i % $total][] = $test;
            }

            $testsToRun = $partitions[$index - 1] ?? [];
            self::$timeBalanced = true;
            self::$shardsOutdated = $newTests !== [];
        } else {
            $isInCurrentShard = fn (int $key): bool => $key % $total === ($index - 1);
            $testsToRun = array_values(array_filter($tests, $isInCurrentShard, ARRAY_FILTER_USE_KEY));
        }

        self::$shard = [
            'index' => $index,
            'total' => $total,
            'testsRan' => count($testsToRun),
            'testsCount' => count($tests),
        ];

        if (! $this->hasArgument('--list-tests', $arguments)
            && ! $this->hasArgument('--list-tests-xml', $arguments)
            && ! $this->hasArgument('--list-groups', $arguments)
            && ! $this->hasArgument('--list-suites', $arguments)
            && ! $this->hasArgument('--dry-run', $arguments)) {
            $expected = [];

            foreach ($testsToRun as $testClass) {
                foreach (array_keys($this->discoveredTestIds[$testClass] ?? []) as $testId) {
                    $expected[$testId] = true;
                }
            }

            self::$expectedTestIds = $expected;
            $this->registerIntegritySubscribers();

            if (Parallel::isEnabled()) {
                self::$integrityRunId = bin2hex(random_bytes(16));
                Parallel::setGlobal(self::INTEGRITY_GLOBAL, self::$integrityRunId);
            }
        }

        $filter = $testsToRun === [] ? '(?!)' : sprintf('^(?:P\\\\)?(?:%s)', $this->buildFilterArgument($testsToRun));

        $this->ensureFilterLengthIsSafe($filter);

        $arguments = [...$arguments, '--filter', $filter];

        if ($this->hasArgument('--do-not-fail-on-empty-test-suite', $arguments) || $this->hasArgument('--fail-on-empty-test-suite', $arguments)) {
            return $arguments;
        }

        return $this->pushArgument('--do-not-fail-on-empty-test-suite', $arguments);
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    private function handleUpdateShards(array $arguments): array
    {
        if ($this->hasArgument('--shard', $arguments)) {
            throw new InvalidOption('The [--update-shards] option cannot be combined with [--shard].');
        }

        $arguments = $this->popArgument('--update-shards', $arguments);

        self::$updateShards = true;

        /** @phpstan-ignore-next-line */
        self::$knownTests = $this->allTests($arguments);

        if ($this->hasArgument('--parallel', $arguments) || $this->hasArgument('-p', $arguments)) {
            Parallel::setGlobal('UPDATE_SHARDS', true);
            Parallel::setGlobal('SHARD_RUN_ID', uniqid('pest-shard-', true));
        } else {
            Event\Facade::instance()->registerSubscriber(new EnsureShardTimingStarted);
            Event\Facade::instance()->registerSubscriber(new EnsureShardTimingFinished);
        }

        return $arguments;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function allTests(array $arguments): array
    {
        $command = $this->buildListTestIdsCommand(
            $arguments,
            TestSuite::getInstance()->testPath,
        );

        $output = new Process($command)->setTimeout(120)->mustRun()->getOutput();

        return $this->parseListTestIdsOutput($output);
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    private function removeParallelArguments(array $arguments): array
    {
        $filtered = [];
        $skipProcessesValue = false;

        foreach ($arguments as $argument) {
            if ($skipProcessesValue) {
                $skipProcessesValue = false;

                continue;
            }

            if ($argument === '--processes') {
                $skipProcessesValue = true;

                continue;
            }

            if (in_array($argument, ['--parallel', '-p'], true) || str_starts_with($argument, '--processes=')) {
                continue;
            }

            $filtered[] = $argument;
        }

        return $filtered;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function buildListTestIdsCommand(array $arguments, string $testPath): array
    {
        $filtered = $this->removeParallelArguments($arguments);

        return [PHP_BINARY, ...$filtered, '--test-directory='.$testPath, '--list-test-ids'];
    }

    /** @return list<string> */
    private function parseListTestIdsOutput(string $output): array
    {
        $this->discoveredTestIds = [];
        $plainOutput = preg_replace('/\\x1B\\[[0-9;]*m/', '', $output);

        if ($plainOutput === null) {
            throw new InvalidOption('Shard discovery output cannot be decoded.');
        }

        $plainOutput = rtrim($plainOutput, chr(13).chr(10));

        if ($plainOutput === '') {
            return [];
        }

        $originalClasses = [];

        foreach (explode(chr(10), $plainOutput) as $entry) {
            $entry = rtrim($entry, chr(13));

            if (preg_match('/^((?:P\\\\)?[A-Za-z_\x{0080}-\x{10FFFF}][A-Za-z0-9_\x{0080}-\x{10FFFF}]*(?:\\\\[A-Za-z_\x{0080}-\x{10FFFF}][A-Za-z0-9_\x{0080}-\x{10FFFF}]*)*)::(.+)$/uD', $entry, $matches) !== 1) {
                throw new InvalidOption('An unrecognised test ID was found in [--list-test-ids]. Refusing incomplete shard discovery.');
            }

            $originalClass = $matches[1];
            $class = str_starts_with($originalClass, 'P\\\\') ? substr($originalClass, 2) : $originalClass;

            if (isset($originalClasses[$class]) && $originalClasses[$class] !== $originalClass) {
                throw new InvalidOption(sprintf(
                    'Sharding cannot distinguish [%s] from [%s] after class normalisation.',
                    $originalClasses[$class],
                    $originalClass,
                ));
            }

            $normalisedId = $class.'::'.$matches[2];

            if (isset($this->discoveredTestIds[$class][$normalisedId])) {
                throw new InvalidOption(sprintf('Duplicate test ID [%s] in shard discovery.', $normalisedId));
            }

            $this->discoveredTestIds[$class][$normalisedId] = true;
            $originalClasses[$class] = $originalClass;
        }

        return array_keys($this->discoveredTestIds);
    }

    /**
     * @param  array<int, string>  $testsToRun
     */
    private function buildFilterArgument(array $testsToRun): string
    {
        if ($testsToRun === []) {
            return '';
        }

        /** @var array<string, mixed> $tree */
        $tree = [];
        foreach ($testsToRun as $class) {
            $parts = explode('\\', $class);
            $current = &$tree;
            foreach ($parts as $part) {
                $current[$part] ??= [];
                $current = &$current[$part];
            }
            $current[self::CLASS_END] = true;
        }

        $buildRegex = function (array $tree) use (&$buildRegex): string {
            $parts = [];
            foreach ($tree as $key => $sub) {
                if ($key === self::CLASS_END) {
                    continue;
                }

                assert(is_array($sub));

                $endsWithClass = isset($sub[self::CLASS_END]);
                $sub = array_diff_key($sub, [self::CLASS_END => null]);
                $subRegex = $sub === [] ? '' : $buildRegex($sub);

                $segment = preg_quote($key, '/');

                if ($subRegex !== '') {
                    $segment .= '\\\\'.(count($sub) > 1 ? '('.$subRegex.')' : $subRegex);
                }

                if ($endsWithClass && $subRegex !== '') {
                    $parts[] = '(?:'.preg_quote($key, '/').'::|'.$segment.')';

                    continue;
                }

                $parts[] = $endsWithClass ? $segment.'::' : $segment;
            }

            return implode('|', $parts);
        };

        return $buildRegex($tree);
    }

    /**
     * @throws InvalidOption
     */
    private function ensureFilterLengthIsSafe(string $filter): void
    {
        $maxLength = (int) (getenv('PEST_SHARD_MAX_FILTER_LENGTH') ?: self::MAX_FILTER_LENGTH);

        if (strlen($filter) > $maxLength) {
            throw new InvalidOption(sprintf(
                'The generated filter for this shard is too long (%d characters). '.
                'This can cause issues with some environments (limit is %d characters). '.
                'Please increase the number of shards (e.g., use 1/4 instead of 1/2) to reduce the filter length.',
                strlen($filter),
                $maxLength
            ));
        }
    }

    public function addOutput(int $exitCode): int
    {
        $exitCode = $this->verifyShardIntegrity($exitCode);
        self::$passed = $exitCode === 0;

        if (self::$updateShards && self::$passed && ! Parallel::isWorker()) {
            self::$collectedTimings = $this->collectTimings();

            $count = self::$knownTests !== null
                ? count(array_intersect_key(self::$collectedTimings, array_flip(self::$knownTests)))
                : count(self::$collectedTimings);

            $this->output->writeln(sprintf(
                '  <fg=gray>Shards:</>   <fg=default>shards.json updated with timings for %d test class%s.</>',
                $count,
                $count === 1 ? '' : 'es',
            ));
        }

        if (self::$shard === null) {
            return $exitCode;
        }

        [
            'index' => $index,
            'total' => $total,
            'testsRan' => $testsRan,
            'testsCount' => $testsCount,
        ] = self::$shard;

        $this->output->writeln(sprintf(
            '  <fg=gray>Shard:</>    <fg=default>%d of %d</> — %d file%s ran, out of %d%s.',
            $index,
            $total,
            $testsRan,
            $testsRan === 1 ? '' : 's',
            $testsCount,
            self::$timeBalanced ? ' <fg=gray>(time-balanced)</>' : '',
        ));

        if (self::$shardsOutdated) {
            $this->output->writeln('  <fg=yellow;options=bold>WARN</>  <fg=default>The [tests/.pest/shards.json] file is out of date. Run [--update-shards] to update it.</>');
        }

        return $exitCode;
    }

    public function terminate(): void
    {
        if (Parallel::isWorker() && self::$integrityRunId !== null) {
            RunIntegrity::writeWorkerEvidence(self::$integrityRunId);
        }

        if (! self::$updateShards) {
            return;
        }

        if (Parallel::isWorker()) {
            $this->writeWorkerTimings();

            return;
        }

        if (! self::$passed) {
            return;
        }

        $timings = self::$collectedTimings ?? $this->collectTimings();

        if ($timings === []) {
            return;
        }

        $this->writeTimings($timings);
    }

    private function registerIntegritySubscribers(): void
    {
        RunIntegrity::activate();

        Event\Facade::instance()->registerSubscriber(new EnsureShardTestIsFinished);
        Event\Facade::instance()->registerSubscriber(new EnsureShardTestIsSkipped);
    }

    private function verifyShardIntegrity(int $exitCode): int
    {
        if (self::$expectedTestIds === null) {
            return $exitCode;
        }

        $observed = RunIntegrity::observed();

        if (self::$integrityRunId !== null) {
            $workers = RunIntegrity::collectWorkerEvidence(self::$integrityRunId);

            if ($workers === null) {
                if ($exitCode === 0) {
                    $this->output->writeln('  <fg=red;options=bold>ERROR</> Shard integrity evidence is unreadable or corrupt.');

                    return 1;
                }

                return $exitCode;
            }

            $observed += $workers;
        }

        if ($exitCode !== 0) {
            return $exitCode;
        }

        ['missing' => $missing, 'unexpected' => $unexpected] = RunIntegrity::difference(self::$expectedTestIds, $observed);

        if ($missing === [] && $unexpected === []) {
            return $exitCode;
        }

        $this->output->writeln(sprintf(
            '  <fg=red;options=bold>ERROR</> Shard integrity failed: %d missing and %d unexpected test IDs.',
            count($missing),
            count($unexpected),
        ));

        foreach (['missing' => $missing, 'unexpected' => $unexpected] as $kind => $classes) {
            if ($classes !== []) {
                $this->output->writeln(sprintf('  <fg=gray>%s:</> %s', ucfirst($kind), implode(', ', array_slice($classes, 0, 5))));
            }
        }

        return 1;
    }

    /**
     * @return array<string, float>
     */
    private function collectTimings(): array
    {
        $runId = Parallel::getGlobal('SHARD_RUN_ID');

        if (is_string($runId)) {
            return $this->readWorkerTimings($runId);
        }

        return EnsureShardTimingsAreCollected::timings();
    }

    private function writeWorkerTimings(): void
    {
        $timings = EnsureShardTimingsAreCollected::timings();

        if ($timings === []) {
            return;
        }

        $runId = Parallel::getGlobal('SHARD_RUN_ID');

        if (! is_string($runId)) {
            return;
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'__pest_sharding_'.$runId.'-'.getmypid().'.json';

        file_put_contents($path, json_encode($timings, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, float>
     */
    private function readWorkerTimings(string $runId): array
    {
        $pattern = sys_get_temp_dir().DIRECTORY_SEPARATOR.'__pest_sharding_'.$runId.'-*.json';
        $files = glob($pattern);

        if ($files === false || $files === []) {
            return [];
        }

        $merged = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $timings = json_decode($contents, true);

            if (is_array($timings)) {
                $merged = array_merge($merged, $timings);
            }

            unlink($file);
        }

        return $merged;
    }

    private function shardsPath(): string
    {
        $testSuite = TestSuite::getInstance();

        return implode(DIRECTORY_SEPARATOR, [$testSuite->rootPath, $testSuite->testPath, '.pest', 'shards.json']);
    }

    /**
     * @return array<string, float>|null
     */
    private function loadShardsFile(): ?array
    {
        $path = $this->shardsPath();

        if (! file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidOption('The [tests/.pest/shards.json] file could not be read. Delete it or run [--update-shards] to regenerate.');
        }

        $data = json_decode($contents, true);

        if (! is_array($data) || ! isset($data['timings']) || ! is_array($data['timings'])) {
            throw new InvalidOption('The [tests/.pest/shards.json] file is corrupted. Delete it or run [--update-shards] to regenerate.');
        }

        return $data['timings'];
    }

    /**
     * @param  list<string>  $tests
     * @param  array<string, float>  $timings
     * @return list<list<string>>
     */
    private function partitionByTime(array $tests, array $timings, int $total): array
    {
        $knownTimings = array_filter(
            array_map(fn (string $test): ?float => $timings[$test] ?? null, $tests),
            fn (?float $t): bool => $t !== null,
        );

        $median = $knownTimings !== [] ? $this->median(array_values($knownTimings)) : 1.0;

        $testsWithTimings = array_map(
            fn (string $test): array => ['test' => $test, 'time' => $timings[$test] ?? $median],
            $tests,
        );

        usort($testsWithTimings, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        /** @var list<list<string>> */
        $bins = array_fill(0, $total, []);
        /** @var non-empty-list<float> */
        $binTimes = array_fill(0, $total, 0.0);

        foreach ($testsWithTimings as $item) {
            $minIndex = array_search(min($binTimes), $binTimes, strict: true);
            assert(is_int($minIndex));

            $bins[$minIndex][] = $item['test'];
            $binTimes[$minIndex] += $item['time'];
        }

        return $bins;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        $count = count($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * @param  array<string, float>  $timings
     */
    private function writeTimings(array $timings): void
    {
        $path = $this->shardsPath();

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (self::$knownTests !== null) {
            $knownSet = array_flip(self::$knownTests);
            $timings = array_intersect_key($timings, $knownSet);
        }

        ksort($timings);

        $canonical = self::$knownTests ?? array_keys($timings);
        sort($canonical);

        file_put_contents($path, json_encode([
            'timings' => $timings,
            'checksum' => md5(implode("\n", $canonical)),
            'updated_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @return array{index: int, total: int}
     */
    public static function getShard(InputInterface $input): array
    {
        if ($input->hasParameterOption('--'.self::SHARD_OPTION)) {
            $shard = $input->getParameterOption('--'.self::SHARD_OPTION);
        } else {
            $shard = null;
        }

        if (! is_string($shard) || ! preg_match('/^\d+\/\d+$/', $shard)) {
            throw new InvalidOption('The [--shard] option must be in the format "index/total".');
        }

        [$index, $total] = explode('/', $shard);

        if (! is_numeric($index) || ! is_numeric($total)) {
            throw new InvalidOption('The [--shard] option must be in the format "index/total".');
        }

        if ($index <= 0 || $total <= 0 || $index > $total) {
            throw new InvalidOption('The [--shard] option index must be a non-negative integer less than the total number of shards.');
        }

        $index = (int) $index;
        $total = (int) $total;

        return [
            'index' => $index,
            'total' => $total,
        ];
    }
}
