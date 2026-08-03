<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\BackupVerifyOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the archive checksum verifier.
 *
 * The verifier is pure (no env, no runtime), so every case runs over a real temp file:
 * the point of the class is what it concludes about bytes on disk, and a mocked
 * filesystem would only pin the mock.
 */
final class BackupVerifierTest extends TestCase
{
    /** @var list<string> Temp archives to unlink after the test. */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->paths = [];
        parent::tearDown();
    }

    public function testAnUntouchedArchiveVerifiesOk(): void
    {
        $path = $this->writeArchive('backup payload');

        $result = new BackupVerifier()->verify($path, $this->metadataFor($path, $this->digestOf($path)));

        $this->assertSame(BackupVerifyOutcome::OK, $result->outcome);
        $this->assertSame($result->expected, $result->actual);
        $this->assertNull($result->reason);
    }

    public function testAFlippedByteIsAMismatchAndBothDigestsAreReported(): void
    {
        $path = $this->writeArchive('backup payload');
        $metadata = $this->metadataFor($path, $this->digestOf($path));

        // Same length, different content: only the digest can tell these apart.
        file_put_contents($path, 'backup paylOad');

        $result = new BackupVerifier()->verify($path, $metadata);

        $this->assertSame(BackupVerifyOutcome::MISMATCH, $result->outcome);
        $this->assertSame($metadata->sha256, $result->expected);
        $this->assertNotNull($result->actual);
        $this->assertNotSame($result->expected, $result->actual);
    }

    public function testATruncatedArchiveIsCaughtBySizeWithoutHashing(): void
    {
        $path = $this->writeArchive('backup payload');
        $metadata = $this->metadataFor($path, $this->digestOf($path));

        file_put_contents($path, 'backup');

        $result = new BackupVerifier()->verify($path, $metadata);

        $this->assertSame(BackupVerifyOutcome::MISMATCH, $result->outcome);
        $this->assertSame('size differs', $result->reason);
        // Nothing was hashed: a size that already disagrees settles it, and the archives
        // this runs against in production are gigabytes.
        $this->assertNull($result->actual);
    }

    public function testAMissingArchiveIsReportedAsMissingNotCorrupt(): void
    {
        $path = $this->writeArchive('backup payload');
        $metadata = $this->metadataFor($path, $this->digestOf($path));
        unlink($path);

        $result = new BackupVerifier()->verify($path, $metadata);

        $this->assertSame(BackupVerifyOutcome::ARCHIVE_MISSING, $result->outcome);
        $this->assertSame($metadata->sha256, $result->expected);
    }

    public function testASidecarWithoutADigestIsNothingToCheck(): void
    {
        // Every sidecar written before HIL-435 reads back with a null digest; calling those
        // corrupt would turn the whole accumulated history red on release.
        $path = $this->writeArchive('backup payload');

        $result = new BackupVerifier()->verify($path, $this->metadataFor($path, null));

        $this->assertSame(BackupVerifyOutcome::NO_DIGEST, $result->outcome);
        $this->assertNull($result->expected);
        $this->assertNull($result->actual);
    }

    /**
     * Writes a temp archive and remembers it for cleanup.
     *
     * @param string $content Archive bytes
     * @return string Absolute archive path
     */
    private function writeArchive(string $content): string
    {
        $path = sys_get_temp_dir() . '/hilos-verify-' . uniqid('', true) . '.tar.gz';
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return $path;
    }

    /**
     * Builds the sidecar metadata a freshly written archive would carry.
     *
     * @param string $path Archive the metadata describes
     * @param ?string $sha256 Digest to record; null stands for a sidecar written before digests existed
     * @return BackupMetadata Sidecar metadata for the archive
     */
    private function metadataFor(string $path, ?string $sha256): BackupMetadata
    {
        return new BackupMetadata(
            id: 'bk1',
            createdAt: '2026-08-02T03:00:00+00:00',
            env: 'prod',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: (int)filesize($path),
            durationSeconds: 5,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: $sha256,
        );
    }

    /**
     * @param string $path File to hash
     * @return string The file's digest under the algorithm the write path uses
     */
    private function digestOf(string $path): string
    {
        $digest = hash_file(BackupVerifier::DIGEST_ALGO, $path);
        $this->assertNotFalse($digest);

        return $digest;
    }
}
