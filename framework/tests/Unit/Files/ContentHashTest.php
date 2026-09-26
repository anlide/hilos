<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\ContentHash;
use PHPUnit\Framework\TestCase;

/**
 * Tests the content fingerprint (HIL-136).
 *
 * The fingerprint is counted in pieces while chunks arrive, and a duplicate check compares it with
 * one taken elsewhere - so a fingerprint taken in pieces has to be the one of the whole, and the
 * shape judge has to refuse what a fingerprint never looks like.
 */
final class ContentHashTest extends TestCase
{
    public function testAFingerprintTakenInPiecesIsTheOneOfTheWhole(): void
    {
        $hash = ContentHash::start();
        $hash->update('first piece, ');
        $hash->update('');
        $hash->update('second piece');

        $this->assertSame(hash('sha256', 'first piece, second piece'), $hash->finish());
    }

    public function testNothingAddedIsTheFingerprintOfAnEmptyFile(): void
    {
        $this->assertSame(hash('sha256', ''), ContentHash::start()->finish());
    }

    public function testAFingerprintIsTakenOnce(): void
    {
        $hash = ContentHash::start();
        $hash->finish();

        $this->expectException(LogicException::class);
        $hash->finish();
    }

    public function testNothingIsAddedAfterTheFingerprintIsTaken(): void
    {
        $hash = ContentHash::start();
        $hash->finish();

        $this->expectException(LogicException::class);
        $hash->update('late');
    }

    public function testAFileOnDiskIsFingerprintedByItsBytes(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'hilos-content-hash-');
        file_put_contents($path, 'bytes of a file');
        try {
            $this->assertSame(hash('sha256', 'bytes of a file'), ContentHash::ofFile($path));
        } finally {
            unlink($path);
        }
    }

    public function testOnlySixtyFourLowercaseHexCharactersAreAFingerprint(): void
    {
        $this->assertTrue(ContentHash::isValid(hash('sha256', 'x')));
        $this->assertSame(ContentHash::LENGTH, strlen(hash(ContentHash::ALGORITHM, 'x')));

        $this->assertFalse(ContentHash::isValid(strtoupper(hash('sha256', 'x'))), 'upper case');
        $this->assertFalse(ContentHash::isValid(substr(hash('sha256', 'x'), 1)), '63 characters');
        $this->assertFalse(ContentHash::isValid(hash('sha256', 'x') . '0'), '65 characters');
        $this->assertFalse(ContentHash::isValid(str_repeat('g', ContentHash::LENGTH)), 'not hex');
        $this->assertFalse(ContentHash::isValid(hash('sha256', 'x') . "\n"), 'a trailing line break');
        $this->assertFalse(ContentHash::isValid(''), 'empty');
    }
}
