<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HttpConstants;
use Hilos\DataExport\DataExportDownloadResponse;
use Hilos\Files\Download\FileDownloadResponse;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsFile;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\TestCase;

final class DataExportDownloadResponseTest extends TestCase
{
    private string $directory;
    private FsFile $file;

    /** Uses a real archive file to cover the byte ceiling and transport headers. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/export-response-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $fs = new class($this->directory) extends FsContext {
            /** @param string $path Test directory */
            public function __construct(private readonly string $path) { }
            /** Registers test storage. */
            public function configure(): void { $this->registerDirectory(self::DATA_EXPORT, $this->path, DirectoryScope::CLUSTER); }
        };
        $fs->configure();
        $this->file = $fs->getDirectory(FsContext::DATA_EXPORT)['copy.zip'];
    }

    /** Removes only this test's file. */
    protected function tearDown(): void
    {
        if (is_file($this->file->getPath())) { unlink($this->file->getPath()); }
        rmdir($this->directory);
    }

    /** Direct downloads are private attachments with a date-based name. */
    public function testPrivateZipHeadersAndBytes(): void
    {
        file_put_contents($this->file->getPath(), "PK\x00\xff");
        $response = $this->response('');
        self::assertSame(200, $response->reply->status);
        self::assertSame("PK\x00\xff", $response->reply->body);
        self::assertSame('application/zip', $response->reply->headers['Content-Type']);
        self::assertSame('private, no-store', $response->reply->headers['Cache-Control']);
        self::assertSame('nosniff', $response->reply->headers['X-Content-Type-Options']);
        self::assertStringContainsString('attachment; filename="your-data-2026-09-27.zip"', $response->reply->headers['Content-Disposition']);
    }

    /** Large archives require nginx; exactly the direct limit is allowed. */
    public function testDirectByteCeilingAndNginxBypass(): void
    {
        file_put_contents($this->file->getPath(), str_repeat('x', FileDownloadResponse::DIRECT_MAX_BYTES));
        self::assertSame(200, $this->response('')->reply->status);
        file_put_contents($this->file->getPath(), 'x', FILE_APPEND);
        clearstatcache(true, $this->file->getPath());
        $direct = $this->response('');
        self::assertSame(500, $direct->reply->status);
        self::assertTrue($direct->tooLarge);
        self::assertSame('no-store', $direct->reply->headers['Cache-Control']);
        $nginx = $this->response('/__exports/');
        self::assertSame(200, $nginx->reply->status);
        self::assertSame('', $nginx->reply->body);
        self::assertSame('/__exports/copy.zip', $nginx->reply->headers['X-Accel-Redirect']);
    }

    /** A missing file never produces a cached redirect. */
    public function testMissingFileIsNotFound(): void
    {
        $reply = $this->response('/__exports')->reply;
        self::assertSame(404, $reply->status);
        self::assertSame('no-store', $reply->headers['Cache-Control']);
    }

    /**
     * @param string $location Optional nginx location
     * @return DataExportDownloadResponse Response under test
     */
    private function response(string $location): DataExportDownloadResponse
    {
        return DataExportDownloadResponse::forFile(
            new HttpRequestDTO('request', HttpConstants::METHOD_GET, '/_hilos/data-export', [], 'session', null),
            $this->file, '2026-09-27 12:00:00', $location,
        );
    }
}
