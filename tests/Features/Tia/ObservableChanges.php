<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ChangedFiles;
use Pest\Plugins\Tia\ContentHash;
use Pest\Plugins\Tia\Fingerprint;
use Pest\Plugins\Tia\Graph;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

test('a PHPDoc-only edit is selected by the real Git based TIA impact graph', function (): void {
    $project = Project::make('master');
    $project->seed('master');

    $source = (string) file_get_contents($project->path('app/Greeter.php'));
    $modified = str_replace('final class Greeter', "/** @permission admin */\nfinal class Greeter", $source);

    expect($modified)->not->toBe($source);

    $project->write('app/Greeter.php', $modified);

    $changed = new ChangedFiles($project->path);
    $diff = $changed->since($project->git()->sha());

    expect($diff)->toContain('app/Greeter.php');

    $graph = Graph::decode(json_encode($project->graph(), JSON_THROW_ON_ERROR), $project->path);

    expect($graph)->toBeInstanceOf(Graph::class)
        ->and($graph->affected($diff))->toContain('tests/Unit/GreeterTest.php')
        ->and($graph->affected($diff))->not->toContain('tests/Unit/CalculatorTest.php');
})->skipOnWindows();

test('runtime PHP Reflection observes a PHPDoc edit that TIA must not ignore', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-tia-runtime-'.bin2hex(random_bytes(8)).'.php';
    $source = "<?php\n/** @permission admin */\nfinal class PestTiaRoleMetadata {}";
    $changed = "<?php\n/** @permission guest */\nfinal class PestTiaRoleMetadata {}";

    $run = static function (string $code) use ($path): string {
        file_put_contents($path, $code);
        $probe = new Process([
            PHP_BINARY,
            '-r',
            'require $argv[1]; echo (new ReflectionClass("PestTiaRoleMetadata"))->getDocComment();',
            $path,
        ]);
        $probe->mustRun();

        return $probe->getOutput();
    };

    try {
        expect($run($source))->toContain('@permission admin')
            ->and($run($changed))->toContain('@permission guest')
            ->and(ContentHash::ofContent($path, $source))->not->toBe(ContentHash::ofContent($path, $changed));
    } finally {
        @unlink($path);
    }
});

test('runtime PHP __LINE__ reflects whitespace edits that TIA must not ignore', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-tia-line-'.bin2hex(random_bytes(8)).'.php';
    $before = "<?php\nreturn __LINE__;\n";
    $after = "<?php\n\nreturn __LINE__;\n";

    $run = static function (string $code) use ($path): string {
        file_put_contents($path, $code);
        $probe = new Process([PHP_BINARY, '-r', 'echo include $argv[1];', $path]);
        $probe->mustRun();

        return $probe->getOutput();
    };

    try {
        expect($run($before))->toBe('2')
            ->and($run($after))->toBe('3')
            ->and(ContentHash::ofContent($path, $before))->not->toBe(ContentHash::ofContent($path, $after));
    } finally {
        @unlink($path);
    }
});

test('old semantic cache schema cannot be replayed after strict content fingerprint upgrade', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-tia-schema-'.bin2hex(random_bytes(8));
    mkdir($root, 0755, true);

    try {
        $current = Fingerprint::compute($root);
        $old = $current;
        $old['structural']['schema'] = 18;

        expect($current['structural']['schema'])->toBe(19)
            ->and(Fingerprint::structuralMatches($old, $current))->toBeFalse()
            ->and(Fingerprint::structuralMatches($current, $current))->toBeTrue();
    } finally {
        @rmdir($root);
    }
});
