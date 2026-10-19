<?php

use Pest\Exceptions\InvalidOption;
use Pest\Plugins\Shard;
use SebastianBergmann\CliParser\Parser;
use PHPUnit\Runner\Version;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('getShard', function (): void {
    it('parses valid shard format', function (string $format, int $expectedIndex, int $expectedTotal): void {
        $input = new ArgvInput(['test', '--shard', $format]);

        $result = Shard::getShard($input);

        expect($result)->toBe([
            'index' => $expectedIndex,
            'total' => $expectedTotal,
        ]);
    })->with([
        ['1/2', 1, 2],
        ['2/2', 2, 2],
        ['1/4', 1, 4],
        ['4/4', 4, 4],
        ['1/10', 1, 10],
        ['10/10', 10, 10],
        ['5/100', 5, 100],
    ]);

    it('throws exception for invalid format', function (array $arguments): void {
        $input = new ArgvInput($arguments);

        Shard::getShard($input);
    })->with([
        [['test', '--shard', 'invalid']],
        [['test', '--shard', '1']],
        [['test', '--shard', '1/']],
        [['test', '--shard', '/2']],
        [['test', '--shard', 'a/b']],
        [['test', '--shard', '1.5/2']],
    ])->throws(InvalidOption::class);

    it('throws exception for invalid index or total values', function (array $arguments): void {
        $input = new ArgvInput($arguments);

        Shard::getShard($input);
    })->with([
        [['test', '--shard', '0/2']],
        [['test', '--shard', '1/0']],
        [['test', '--shard', '3/2']],
        [['test', '--shard', '5/4']],
    ])->throws(InvalidOption::class);
});

describe('buildFilterArgument', function (): void {
    it('generates compact filter for single test', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, ['Tests\\Unit\\ExampleTest']);

        expect($filter)->toBe('Tests\\\\Unit\\\\ExampleTest::');
    });

    it('generates compact filter for multiple tests with common prefix', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, [
            'Tests\\Unit\\Foo\\BarTest',
            'Tests\\Unit\\Foo\\BazTest',
        ]);

        expect($filter)->toBe('Tests\\\\Unit\\\\Foo\\\\(BarTest::|BazTest::)');
    });

    it('generates compact filter for tests with different namespaces', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, [
            'Tests\\Unit\\FooTest',
            'Tests\\Feature\\BarTest',
        ]);

        expect($filter)->toBe('Tests\\\\(Unit\\\\FooTest::|Feature\\\\BarTest::)');
    });

    it('returns empty string for empty test list', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, []);

        expect($filter)->toBeEmpty();
    });

    it('generates compact filter for deeply nested namespaces', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, [
            'Tests\\Unit\\Plugins\\Concerns\\Foo',
            'Tests\\Unit\\Plugins\\Concerns\\Bar',
            'Tests\\Unit\\Plugins\\Concerns\\Baz',
        ]);

        expect($filter)->toBe('Tests\\\\Unit\\\\Plugins\\\\Concerns\\\\(Foo::|Bar::|Baz::)');
    });

    it('handles mix of nested and flat namespaces', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $tests = [
            'Tests\\Unit\\SimpleTest',
            'Tests\\Unit\\Plugins\\Concerns\\HandleArguments',
            'Tests\\Unit\\Plugins\\Concerns\\Validation',
            'Tests\\Unit\\Another\\Deep\\Nested\\Test',
        ];

        $filter = $method->invoke($shard, $tests);

        expect($filter)
            ->toBe(addslashes('Tests\\Unit\\(SimpleTest::|Plugins\\Concerns\\(HandleArguments::|Validation::)|Another\\Deep\\Nested\\Test::)'));
    });

    it('does not match a class outside of the shard when another class name is its prefix', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, ['Tests\\Feature\\After']);

        expect(preg_match('{'.$filter.'}i', 'P\Tests\Feature\AfterAll::deletes file after all'))->toBe(0)
            ->and('P\Tests\Feature\After::it runs')->toMatch('{'.$filter.'}i');
    });

    it('still matches dataset variants after the class boundary', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, ['Tests\\Feature\\After']);

        expect('P\Tests\Feature\After::it runs with data set "foo"')->toMatch('{'.$filter.'}i');
    });

    it('keeps a class matchable when another class nests below its namespace', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, ['Tests\\Unit\\After', 'Tests\\Unit\\After\\AfterAll']);

        expect($filter)->toBe('Tests\\\\Unit\\\\(?:After::|After\\\\AfterAll::)')
            ->and('P\Tests\Unit\After::x')->toMatch('{'.$filter.'}i')
            ->and('P\Tests\Unit\After\AfterAll::y')->toMatch('{'.$filter.'}i');
    });
    it('keeps the filter whole when the parallel runner passes it as --filter=VALUE', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, ['Tests\\Unit\\AlphaTest', 'Tests\\Unit\\After', 'Tests\\Unit\\After\\AfterAll']);

        [$options] = new Parser()->parse(['--filter='.$filter], '', ['filter=']);

        expect($options)->toBe([['--filter', $filter]]);
    });

    it('matches a class-based test of the shard', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildFilterArgument');

        $filter = $method->invoke($shard, ['Tests\\Unit\\AlphaTest']);

        expect('Tests\Unit\AlphaTest::test_it_runs')->toMatch('{'.$filter.'}i')
            ->and('Tests\Unit\AlphaTestTwo::test_it_runs')->not->toMatch('{'.$filter.'}i');
    });
});

describe('ensureFilterLengthIsSafe', function (): void {
    it('accepts filter within length limit', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('ensureFilterLengthIsSafe');

        $filter = str_repeat('a', 1000);

        $method->invoke($shard, $filter);

        expect(true)->toBeTrue();
    });

    it('throws exception when filter exceeds default limit', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('ensureFilterLengthIsSafe');

        $filter = str_repeat('a', 32769);

        $method->invoke($shard, $filter);
    })->throws(InvalidOption::class, 'The generated filter for this shard is too long');

    it('respects custom limit from environment variable', function (): void {
        putenv('PEST_SHARD_MAX_FILTER_LENGTH=1000');

        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('ensureFilterLengthIsSafe');

        $filter = str_repeat('a', 1001);

        try {
            $method->invoke($shard, $filter);
            expect(false)->toBeTrue('Should have thrown exception');
        } catch (InvalidOption $e) {
            expect($e->getMessage())->toContain('1001 characters')
                ->and($e->getMessage())->toContain('limit is 1000 characters');
        } finally {
            putenv('PEST_SHARD_MAX_FILTER_LENGTH');
        }
    });

    it('accepts filter within custom limit', function (): void {
        putenv('PEST_SHARD_MAX_FILTER_LENGTH=1000');

        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('ensureFilterLengthIsSafe');

        $filter = str_repeat('a', 999);

        try {
            $method->invoke($shard, $filter);
            expect(true)->toBeTrue();
        } catch (InvalidOption) {
            expect(false)->toBeTrue('Should not have thrown exception');
        } finally {
            putenv('PEST_SHARD_MAX_FILTER_LENGTH');
        }
    });
});

describe('handleArguments', function (): void {
    it('returns original arguments when shard option is not present', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $arguments = ['bin/pest', 'tests/', '--parallel'];

        $result = $shard->handleArguments($arguments);

        expect($result)->toBe($arguments);
    });

    it('removes parallel arguments from test discovery', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('removeParallelArguments');

        $arguments = ['bin/pest', '--parallel', '--processes=4', 'tests/', '-p'];

        $result = $method->invoke($shard, $arguments);

        expect($result)->toBe(['bin/pest', 'tests/']);
    });
});

describe('parseListTestIdsOutput', function (): void {
    it('parses Tests\\ namespaced classes from --list-test-ids output', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('parseListTestIdsOutput');

        $listOutput = <<<'OUT'
P\Tests\Features\After::__pest_evaluable_it_runs
P\Tests\Features\After::__pest_evaluable_it_runs_twice
P\Tests\Unit\Foo::test_bar
OUT;

        expect($method->invoke($shard, $listOutput))->toBe([
            'Tests\\Features\\After',
            'Tests\\Unit\\Foo',
        ]);
    });

    it('deduplicates repeated class names from multiple test methods', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('parseListTestIdsOutput');

        $listOutput = <<<'OUT'
P\Tests\Same::method_a
P\Tests\Same::method_b
P\Tests\Same::method_c
OUT;

        expect($method->invoke($shard, $listOutput))->toBe(['Tests\\Same']);
    });

    it('rejects unknown test entries instead of silently certifying a partial inventory', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        $method->invoke($shard, <<<'OUT'
P\Tests\Example::test_first
?invalid-test-entry
OUT);
    })->throws(InvalidOption::class, 'An unrecognised test ID');

    it('rejects native and generated class names that collide after normalisation', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        $method->invoke($shard, <<<'OUT'
P\Acme\Example::test_one
Acme\Example::test_two
OUT);
    })->throws(InvalidOption::class, 'cannot distinguish');

    it('handles ANSI-styled test discovery without losing class names', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        expect($method->invoke($shard, "\x1b[32mP\\Acme\\Example::test_one\x1b[0m\n"))->toBe(['Acme\\Example']);
    });

    it('parses Unicode PHP class identifiers from native and generated test entries', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        $listOutput = <<<'OUT'
P\Acme\ŚlimakTest::test_one
Acme\ŻółwTest::test_two
OUT;

        expect($method->invoke($shard, $listOutput))->toBe([
            'Acme\\ŚlimakTest',
            'Acme\\ŻółwTest',
        ]);
    });

    it('returns an empty list for a genuinely empty inventory', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('parseListTestIdsOutput');

        expect($method->invoke($shard, ''))->toBeEmpty()
            ->and($method->invoke($shard, chr(13).chr(10)))->toBeEmpty();
    });

    it('parses non-Tests namespaced classes', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('parseListTestIdsOutput');

        $listOutput = <<<'OUT'
P\Acme\Sharding\OneTest::test_foo
P\Acme\Sharding\TwoTest::test_bar
App\Suite\BazTest::test_qux
OUT;

        expect($method->invoke($shard, $listOutput))->toBe([
            'Acme\\Sharding\\OneTest',
            'Acme\\Sharding\\TwoTest',
            'App\\Suite\\BazTest',
        ]);
    });

    it('parses unnamespaced top-level classes', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('parseListTestIdsOutput');

        expect($method->invoke($shard, 'P\FooTest::test_bar'))->toBe(['FooTest']);
    });

    it('strips the P\\ Pest prefix but keeps the rest of the FQCN', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('parseListTestIdsOutput');

        $listOutput = <<<'OUT'
P\Acme\OneTest::a
Acme\TwoTest::b
OUT;

        expect($method->invoke($shard, $listOutput))->toBe([
            'Acme\\OneTest',
            'Acme\\TwoTest',
        ]);
    });

    it('ignores framing blank lines around a native PHPUnit ID inventory', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');
        $header = Version::getVersionString().PHP_EOL.PHP_EOL;

        expect($method->invoke($shard, $header.PHP_EOL.'P\\Acme\\RealTest::method'.PHP_EOL.PHP_EOL))
            ->toBe(['Acme\\RealTest']);
    })->group('integration');

    it('accepts the exact native PHPUnit CLI version header without treating it as a test', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');
        $header = Version::getVersionString().PHP_EOL.PHP_EOL;

        expect($method->invoke($shard, $header.'P\\Acme\\RealTest::method'))->toBe(['Acme\\RealTest']);
    })->group('integration');

    it('rejects injected diagnostics mixed with native test IDs', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        $method->invoke($shard, "P\\Acme\\RealTest::method\nWARN unexpected output");
    })->throws(InvalidOption::class, 'An unrecognised test ID');

    it('rejects duplicated method and dataset IDs', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        $method->invoke($shard, "P\\Acme\\RealTest::method#first\nP\\Acme\\RealTest::method#first");
    })->throws(InvalidOption::class, 'Duplicate test ID')->group('integration');

    it('retains a canonical ID for each dataset row in native discovery', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('parseListTestIdsOutput');

        expect($method->invoke($shard, "P\\Acme\\RealTest::method#first\nP\\Acme\\RealTest::method#second"))->toBe(['Acme\\RealTest']);

        $property = new ReflectionProperty(Shard::class, 'discoveredTestIds');

        expect($property->getValue($shard))->toBe([
            'Acme\\RealTest' => [
                'Acme\\RealTest::method#first' => true,
                'Acme\\RealTest::method#second' => true,
            ],
        ]);
    })->group('integration');
});

describe('buildListTestIdsCommand', function (): void {
    it('keeps TIA disabled during machine-readable discovery even when requested on the parent', function (): void {
        $shard = new Shard(new BufferedOutput);
        $method = new ReflectionClass($shard)->getMethod('buildListTestIdsCommand');
        $command = $method->invoke($shard, ['bin/pest', '--tia'], 'tests');

        expect($command)->toContain('--tia', '--no-tia', '--list-test-ids');
    })->group('integration');

    it('builds the list-test-ids command with the forwarded --test-directory', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildListTestIdsCommand');

        $command = $method->invoke($shard, ['bin/pest', '--update-shards'], 'custom/suite');

        expect($command)->toBe([
            PHP_BINARY,
            'bin/pest',
            '--update-shards',
            '--test-directory=custom/suite',
            '--no-tia',
            '--list-test-ids',
        ]);
    });

    it('strips --parallel and -p when building the list-test-ids command', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildListTestIdsCommand');

        $command = $method->invoke($shard, ['bin/pest', '--parallel', '--update-shards', '-p'], 'tests');

        expect($command)->toBe([
            PHP_BINARY,
            'bin/pest',
            '--update-shards',
            '--test-directory=tests',
            '--no-tia',
            '--list-test-ids',
        ]);
    });

    it('forwards --test-directory even when input arguments include one', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildListTestIdsCommand');

        $command = $method->invoke($shard, ['bin/pest'], 'suites');

        expect($command)->toContain('--test-directory=suites');
    });

    it('strips --processes=N when building the list-test-ids command', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildListTestIdsCommand');

        $command = $method->invoke($shard, ['bin/pest', '--parallel', '--processes=4', '--update-shards'], 'tests');

        expect($command)->toBe([
            PHP_BINARY,
            'bin/pest',
            '--update-shards',
            '--test-directory=tests',
            '--no-tia',
            '--list-test-ids',
        ]);
    });

    it('strips --processes N (space-separated) when building the list-test-ids command', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $method = $reflection->getMethod('buildListTestIdsCommand');

        $command = $method->invoke($shard, ['bin/pest', '--parallel', '--processes', '4', '--update-shards'], 'tests');

        expect($command)->not->toContain('--processes')
            ->and($command)->toContain('--update-shards')
            ->and($command)->toBe([
                PHP_BINARY,
                'bin/pest',
                '--update-shards',
                '--test-directory=tests',
                '--no-tia',
                '--list-test-ids',
            ]);
    });
});

describe('addOutput', function (): void {
    it('displays shard information after test execution', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $property = $reflection->getProperty('shard');
        $property->setValue(null, [
            'index' => 2,
            'total' => 4,
            'testsRan' => 25,
            'testsCount' => 100,
        ]);

        $exitCode = $shard->addOutput(0);
        $outputText = $output->fetch();

        expect($exitCode)->toBe(0)
            ->and($outputText)->toContain('Shard:')
            ->and($outputText)->toContain('2 of 4')
            ->and($outputText)->toContain('25 files ran')
            ->and($outputText)->toContain('out of 100');
    });

    it('uses singular form for single test file', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $property = $reflection->getProperty('shard');
        $property->setValue(null, [
            'index' => 1,
            'total' => 4,
            'testsRan' => 1,
            'testsCount' => 100,
        ]);

        $shard->addOutput(0);
        $outputText = $output->fetch();

        expect($outputText)->toContain('1 file ran')
            ->and($outputText)->not->toContain('1 files');
    });

    it('returns original exit code when shard is not set', function (): void {
        $output = new BufferedOutput;
        $shard = new Shard($output);

        $reflection = new ReflectionClass($shard);
        $property = $reflection->getProperty('shard');
        $property->setValue(null, null);

        $exitCode = $shard->addOutput(1);
        $outputText = $output->fetch();

        expect($exitCode)->toBe(1)
            ->and($outputText)->not->toContain('Shard:');
    });
});

describe('native time-balanced shard allocation', function (): void {
    it('matches the original deterministic allocation with ties, zero times and large shard counts', function (): void {
        $shard = new Shard(new BufferedOutput);
        $partition = new ReflectionClass($shard)->getMethod('partitionByTime');

        $classes = [];
        $timings = [];

        for ($i = 0; $i < 1024; $i++) {
            $class = 'Tests\\Benchmark\\Case'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $classes[] = $class;
            $timings[$class] = (($i * 17) % 37) / 10.0;
        }

        $items = array_map(static fn (string $test): array => [
            'test' => $test,
            'time' => $timings[$test],
        ], $classes);

        usort($items, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        foreach ([8, 64, 128, 256] as $total) {
            $expected = array_fill(0, $total, []);
            $binTimes = array_fill(0, $total, 0.0);

            foreach ($items as $item) {
                $index = array_search(min($binTimes), $binTimes, strict: true);
                assert(is_int($index));

                $expected[$index][] = $item['test'];
                $binTimes[$index] += $item['time'];
            }

            expect($partition->invoke($shard, $classes, $timings, $total))->toBe($expected);
        }
    })->group('integration');
});
