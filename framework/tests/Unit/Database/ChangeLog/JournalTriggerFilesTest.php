<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\ChangeLog;

use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

/** Reads a trigger's migration number without requiring a generator plan or database. */
final class JournalTriggerFilesTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hilos-trigger-files-' . getmypid();
        mkdir($this->path);
        JournalTriggerFiles::setPath($this->path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
    }

    public function testValidFromReadsMigrationNumber(): void
    {
        file_put_contents($this->path . '/hilos_cl_sample_after_insert.sql',
            "-- valid from migration #85\nCREATE TRIGGER example;\n");
        $this->assertSame(85, JournalTriggerFiles::validFrom('hilos_cl_sample_after_insert'));
    }

    public function testValidFromNamesMissingFile(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('hilos_cl_sample_after_update.sql');
        JournalTriggerFiles::validFrom('hilos_cl_sample_after_update');
    }

    public function testValidFromRejectsBadHeader(): void
    {
        file_put_contents($this->path . '/hilos_cl_sample_after_delete.sql', "-- handmade\nDROP TRIGGER example;\n");
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('hilos_cl_sample_after_delete.sql');
        JournalTriggerFiles::validFrom('hilos_cl_sample_after_delete');
    }
}
