<?php

use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    $file = tempnam(sys_get_temp_dir(), 'pest-test-');

    if ($file === false) {
        throw new RuntimeException('Unable to create a unique temporary test file.');
    }

    $this->tempFile = $file;
});

afterEach(function (): void {
    unlink($this->tempFile);
});

test('pass', function (): void {
    expect($this->tempFile)->toBeWritableFile();
});

test('failures', function (): void {
    expect('/random/path/whatever.file')->toBeWritableFile();
})->throws(ExpectationFailedException::class);

test('failures with custom message', function (): void {
    expect('/random/path/whatever.file')->toBeWritableFile('oh no!');
})->throws(ExpectationFailedException::class, 'oh no!');

test('not failures', function (): void {
    expect($this->tempFile)->not->toBeWritableFile();
})->throws(ExpectationFailedException::class);
