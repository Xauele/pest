<?php

declare(strict_types=1);

namespace NativeShardProof;

use PHPUnit\Framework\TestCase;

final class CollisionTest extends TestCase
{
    public function test_identity(): void
    {
        self::assertTrue(true);

        $proofDir = getenv('PEST_NATIVE_PREFIX_PROOF_DIR');

        if (is_string($proofDir) && $proofDir !== '') {
            self::assertSame(6, file_put_contents($proofDir.DIRECTORY_SEPARATOR.'normal', 'NORMAL', LOCK_EX));
        }
    }
}
