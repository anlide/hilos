<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\DataExport\DataExportArchive;
use Hilos\Fs\FsException;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataExportArchiveTest extends TestCase
{
    private string $directory;

    /** Creates a private directory for each archive. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/hilos-export-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    /** Removes only this test's files. */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** JSON remains readable, an absent section is null, and attachment bytes survive unchanged. */
    public function testWritesPortableUncompressedArchive(): void
    {
        $path = $this->directory . '/copy.building.zip';
        file_put_contents($this->directory . '/source', "\x00\xffattachment\r\n");
        $writer = new DataExportArchive($path);
        $attachment = $writer->file('document.bin', $this->directory . '/source');
        $writer->section('chat', ['name' => 'Александр', 'file' => $attachment]);
        $writer->section('account_deletion', null);
        $writer->text('README.txt', 'Your personal data.');
        $size = $writer->close();

        $archive = new PharData($path);
        self::assertTrue($archive->isFileFormat(Phar::ZIP));
        self::assertSame(filesize($path), $size);
        self::assertGreaterThan(0, $size);
        self::assertSame('files/document.bin', $attachment);
        self::assertSame("\x00\xffattachment\r\n", $archive[$attachment]->getContent());
        self::assertFalse($archive[$attachment]->isCompressed());
        self::assertSame('null', $archive['account_deletion.json']->getContent());
        self::assertSame('Your personal data.', $archive['README.txt']->getContent());
        self::assertSame(['name' => 'Александр', 'file' => $attachment], json_decode($archive['chat.json']->getContent(), true));
        self::assertStringContainsString('Александр', $archive['chat.json']->getContent());
        self::assertStringContainsString("\n", $archive['chat.json']->getContent());
    }

    /** Duplicate names cannot overwrite a section that another contributor already wrote. */
    public function testRefusesDuplicateEntry(): void
    {
        $writer = new DataExportArchive($this->directory . '/copy.zip');
        $writer->section('account', ['id' => 7]);
        $this->expectException(FsException::class);
        $writer->section('account', ['id' => 8]);
    }

    /**
     * @param string $name Unsafe attachment name
     */
    #[DataProvider('unsafeNames')]
    public function testRefusesUnsafeAttachmentName(string $name): void
    {
        $writer = new DataExportArchive($this->directory . '/copy.zip');
        $this->expectException(FsException::class);
        $writer->file($name, $this->directory . '/source');
    }

    /**
     * @return iterable<string, array{string}> Names unsafe on either Unix or Windows extraction
     */
    public static function unsafeNames(): iterable
    {
        yield 'empty' => [''];
        yield 'parent' => ['..'];
        yield 'unix traversal' => ['../outside'];
        yield 'windows traversal' => ['..\\outside'];
        yield 'absolute' => ['/outside'];
        yield 'drive' => ['C:outside'];
        yield 'nul' => ["file\x00.txt"];
    }

    /** A missing attachment fails the whole copy through the filesystem exception boundary. */
    public function testWrapsMissingAttachment(): void
    {
        $writer = new DataExportArchive($this->directory . '/copy.zip');
        $this->expectException(FsException::class);
        $writer->file('missing', $this->directory . '/missing');
    }

    /** Invalid JSON input cannot silently become an empty section. */
    public function testWrapsJsonFailure(): void
    {
        $writer = new DataExportArchive($this->directory . '/copy.zip');
        $this->expectException(FsException::class);
        $writer->section('account', ['name' => "\xff"]);
    }

    /** A closed archive cannot change after its size was recorded. */
    public function testRefusesWritesAfterClose(): void
    {
        $writer = new DataExportArchive($this->directory . '/copy.zip');
        $writer->section('account', ['id' => 7]);
        $writer->close();
        $this->expectException(FsException::class);
        $writer->text('README.txt', 'Late entry');
    }

    /** Restarted work must use a fresh archive rather than inherit unfinished old entries. */
    public function testRefusesExistingPath(): void
    {
        $path = $this->directory . '/copy.zip';
        file_put_contents($path, 'existing');
        $this->expectException(FsException::class);
        new DataExportArchive($path);
    }
}
