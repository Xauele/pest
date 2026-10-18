<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use RuntimeException;

/**
 * @internal
 */
final class NativeShadowComparison
{
    /**
     * @param  list<string>  $coveredFiles
     * @return array{
     *     observed_files: list<string>, covered_files: list<string>,
     *     coverage_only: list<string>, native_only: list<string>,
     *     dynamic_code: bool, safe_to_skip_tests: false
     * }
     */
    public static function compare(mixed $evidence, array $coveredFiles, SourceScope $scope): array
    {
        if (! is_array($evidence)
            || ($evidence['scope'] ?? null) !== 'function-entry-positive-evidence'
            || ($evidence['safe_to_skip_tests'] ?? null) !== false
            || ! is_bool($evidence['dynamic_code'] ?? null)
            || ! isset($evidence['files'])
            || ! is_array($evidence['files'])
            || ! array_is_list($evidence['files'])) {
            throw new RuntimeException('Untrusted native TIA shadow evidence has an invalid proof contract.');
        }

        $observed = [];

        foreach ($evidence['files'] as $file) {
            if (! is_string($file) || $file === '' || ! str_starts_with($file, DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Native TIA shadow emitted a noncanonical source filename.');
            }

            $path = realpath($file) ?: $file;

            if ($scope->contains($path)) {
                $observed[$path] = true;
            }
        }

        $covered = [];

        foreach ($coveredFiles as $file) {
            if ($file === '') {
                throw new RuntimeException('Coverage oracle supplied an empty source filename.');
            }

            $path = realpath($file) ?: $file;

            if ($scope->contains($path)) {
                $covered[$path] = true;
            }
        }

        $nativeFiles = array_keys($observed);
        $oracleFiles = array_keys($covered);
        sort($nativeFiles);
        sort($oracleFiles);

        $coverageOnly = array_values(array_diff($oracleFiles, $nativeFiles));
        $nativeOnly = array_values(array_diff($nativeFiles, $oracleFiles));

        return [
            'observed_files' => $nativeFiles,
            'covered_files' => $oracleFiles,
            'coverage_only' => $coverageOnly,
            'native_only' => $nativeOnly,
            'dynamic_code' => $evidence['dynamic_code'],
            'safe_to_skip_tests' => false,
        ];
    }
}
