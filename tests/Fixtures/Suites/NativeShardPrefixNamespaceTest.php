<?php

declare(strict_types=1);

namespace P\NativeShardProof;

use PHPUnit\Framework\TestCase;

final class CollisionTest extends TestCase
{
    public function test_identity(): void
    {
        self::assertTrue(true);

        $proofDir = getenv('PEST_NATIVE_PREFIX_PROOF_DIR');

        if (is_string($proofDir) && $proofDir !== '') {
            self::assertSame(8, file_put_contents($proofDir.DIRECTORY_SEPARATOR.'p-prefix', 'P-PREFIX', LOCK_EX));
        }
    }
}
