<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeZone;
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

    /** The name carries the readiness date in the archive's own zone, which may differ from the UTC date either way. */
    public function testNameIsDatedInTheZoneOfTheArchive(): void
    {
        file_put_contents($this->file->getPath(), "PK\x00\xff");
        $names = [];
        foreach ([
            [1792711800000, 'Europe/Warsaw'], // 2026-10-22T23:30:00Z
            [1792711800000, 'UTC'],
            [1792724400000, 'America/Los_Angeles'], // 2026-10-23T03:00:00Z
            [1792724400000, 'UTC'],
        ] as [$finishedAt, $timeZone]) {
            $names[] = $this->response('', finishedAt: $finishedAt, timeZone: $timeZone)->reply->headers['Content-Disposition'];
        }
        self::assertSame([
            'attachment; filename="your-data-2026-10-23.zip"; filename*=UTF-8\'\'your-data-2026-10-23.zip',
            'attachment; filename="your-data-2026-10-22.zip"; filename*=UTF-8\'\'your-data-2026-10-22.zip',
            'attachment; filename="your-data-2026-10-22.zip"; filename*=UTF-8\'\'your-data-2026-10-22.zip',
            'attachment; filename="your-data-2026-10-23.zip"; filename*=UTF-8\'\'your-data-2026-10-23.zip',
        ], $names);
    }

    /** A zone ahead of UTC across midnight changes only the name: the ceiling and the other node still refuse. */
    public function testZoneDoesNotChangeTransportRefusals(): void
    {
        file_put_contents($this->file->getPath(), str_repeat('x', FileDownloadResponse::DIRECT_MAX_BYTES + 1));
        $tooLarge = $this->response('', finishedAt: 1792711800000, timeZone: 'Europe/Warsaw');
        self::assertSame(500, $tooLarge->reply->status);
        self::assertTrue($tooLarge->tooLarge);
        self::assertSame('no-store', $tooLarge->reply->headers['Cache-Control']);
        file_put_contents($this->file->getPath(), "PK\x00\xff");
        clearstatcache(true, $this->file->getPath());
        $elsewhere = $this->response('', 'node-b', 'node-a', 1792711800000, 'Europe/Warsaw');
        self::assertSame(500, $elsewhere->reply->status);
        self::assertTrue($elsewhere->onAnotherNode);
        self::assertSame('no-store', $elsewhere->reply->headers['Cache-Control']);
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

    /** Without nginx the daemon's own body reaches only a browser this node holds, whatever the size. */
    public function testABrowserOnAnotherNodeNeedsNginx(): void
    {
        file_put_contents($this->file->getPath(), "PK\x00\xff");
        $direct = $this->response('', 'node-b', 'node-a');
        self::assertSame(500, $direct->reply->status);
        self::assertTrue($direct->onAnotherNode);
        self::assertFalse($direct->tooLarge);
        self::assertSame('no-store', $direct->reply->headers['Cache-Control']);
        $nginx = $this->response('/__exports/', 'node-b', 'node-a');
        self::assertSame(200, $nginx->reply->status);
        self::assertSame('/__exports/copy.zip', $nginx->reply->headers['X-Accel-Redirect']);
        $here = $this->response('', 'node-a', 'node-a');
        self::assertSame(200, $here->reply->status);
        self::assertSame("PK\x00\xff", $here->reply->body);
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
     * @param ?string $originNodeId Node holding the browser's connection, null off a cluster
     * @param ?string $localNodeId Node the export agent runs on, null off a cluster
     * @param int $finishedAt Stored readiness moment of the archive in epoch milliseconds, 2026-09-27T12:00:00Z by default
     * @param string $timeZone IANA zone the archive was written in
     * @return DataExportDownloadResponse Response under test
     */
    private function response(
        string $location,
        ?string $originNodeId = null,
        ?string $localNodeId = null,
        int $finishedAt = 1790510400000,
        string $timeZone = 'UTC',
    ): DataExportDownloadResponse {
        return DataExportDownloadResponse::forFile(
            new HttpRequestDTO('request', HttpConstants::METHOD_GET, '/_hilos/data-export', [], 'session', $originNodeId),
            $this->file, $finishedAt, $location, $localNodeId, new DateTimeZone($timeZone),
        );
    }
}
