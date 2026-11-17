<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupTestResetCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** Exercises the real command body with a disposable backup root and real permissions. */
final class BackupTestResetCommandTest extends TestCase
{
    private EnvAccessor $previousEnv;

    private string $root;

    /** Supplies a fresh test environment and temporary storage. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->root = sys_get_temp_dir() . '/hilos-backup-reset-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/parent/backup', 0777, true);
        putenv('APP_ENV=test');
        putenv('BACKUP_DIR=');
        Hilos::$env = new EnvAccessor();
    }

    /** Removes temporary data after permission and UID restoration. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        if (is_file($this->root . '/parent/backup/archive.gz')) {
            unlink($this->root . '/parent/backup/archive.gz');
        }
        rmdir($this->root . '/parent/backup');
        rmdir($this->root . '/parent');
        rmdir($this->root);
    }

    /** An installation with backup storage disabled does no filesystem work. */
    public function testAnUnsetBackupDirectorySucceeds(): void
    {
        [$code, $output] = $this->executeReset();

        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('is unset', $output);
    }

    /** A genuinely absent root stays absent after a successful no-op. */
    public function testAnAbsentBackupDirectorySucceeds(): void
    {
        putenv('BACKUP_DIR=' . $this->root . '/missing/backup');
        Hilos::$env = new EnvAccessor();

        [$code, $output] = $this->executeReset();

        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertDirectoryDoesNotExist($this->root . '/missing');
    }

    /** An inaccessible ancestor must refuse instead of reporting the archive absent. */
    public function testAnInaccessibleBackupAncestorRefusesAndKeepsTheArchive(): void
    {
        // Load the command's ownership and cleanup paths before dropping privileges.
        $this->executeReset();
        foreach ([FsPath::class, DirectoryNotFoundException::class, FileReadException::class] as $class) {
            self::assertTrue(class_exists($class));
        }
        $backup = $this->root . '/parent/backup';
        file_put_contents($backup . '/archive.gz', 'keep');
        putenv('BACKUP_DIR=' . $backup);
        Hilos::$env = new EnvAccessor();
        $originalUid = posix_geteuid();
        $unprivilegedUid = $originalUid;
        if ($originalUid === 0) {
            $nobody = posix_getpwnam('nobody');
            self::assertIsArray($nobody);
            $unprivilegedUid = $nobody['uid'];
            self::assertNotSame(0, $unprivilegedUid);
        }

        try {
            FsPath::chmod($this->root . '/parent', 0000);
            self::assertTrue(posix_seteuid($unprivilegedUid));
            self::assertSame($unprivilegedUid, posix_geteuid());
            [$code, $output] = $this->executeReset();
        } finally {
            $restored = posix_seteuid($originalUid);
            if ($restored) {
                FsPath::chmod($this->root . '/parent', 0755);
            }
            self::assertTrue($restored);
            self::assertSame($originalUid, posix_geteuid());
        }

        self::assertSame('keep', file_get_contents($backup . '/archive.gz'));
        self::assertSame(ExitCode::ERROR, $code, $output);
        self::assertStringContainsString($backup, $output);
        self::assertStringNotContainsString('does not exist', $output);
        self::assertStringNotContainsString('emptied:', $output);
    }

    /** @return array{int, string} Real command exit code and stdout */
    private function executeReset(): array
    {
        ob_start();
        try {
            return [new BackupTestResetCommand()->execute([], []), ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}
