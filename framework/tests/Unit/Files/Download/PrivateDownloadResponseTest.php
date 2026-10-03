<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Download;

use Hilos\Constants\HttpConstants;
use Hilos\Files\Download\FileDownloadResponse;
use Hilos\Files\Download\PrivateDownloadResponse;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsFile;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * How a file its caller authorized travels to one session (HIL-1234): through nginx, or as the daemon's own
 * bounded body to a browser this node holds, and always as a private attachment of the type and name asked.
 */
final class PrivateDownloadResponseTest extends TestCase
{
    private const string CONTENT_TYPE = 'text/csv; charset=utf-8';
    private const string FILENAME = 'legal-acceptances-2026-09-27.csv';
    private const string XACCEL_LOCATION = '/__hilos_legal_export/';

    private string $directory;
    private FsFile $file;

    /** Uses a real file to cover the byte ceiling and the transport headers. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/private-download-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $fs = new class($this->directory) extends FsContext {
            /** @param string $path Test directory */
            public function __construct(private readonly string $path)
            {
            }

            /** Registers test storage. */
            public function configure(): void
            {
                $this->registerDirectory(self::LEGAL_EXPORT, $this->path, DirectoryScope::CLUSTER);
            }
        };
        $fs->configure();
        $this->file = $fs->getDirectory(FsContext::LEGAL_EXPORT)['export.csv'];
    }

    /** Removes only this test's file. */
    protected function tearDown(): void
    {
        if (is_file($this->file->getPath())) {
            unlink($this->file->getPath());
        }
        rmdir($this->directory);
    }

    public function testTheDirectBodyIsAPrivateAttachmentOfTheTypeAndNameAsked(): void
    {
        file_put_contents($this->file->getPath(), "\xEF\xBB\xBFuser_id\r\n");
        $response = $this->response('');

        self::assertSame(HttpConstants::HTTP_OK, $response->reply->status);
        self::assertSame("\xEF\xBB\xBFuser_id\r\n", $response->reply->body);
        self::assertSame(self::CONTENT_TYPE, $response->reply->headers[HttpConstants::HEADER_CONTENT_TYPE]);
        self::assertSame('private, no-store', $response->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        self::assertSame('nosniff', $response->reply->headers[HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS]);
        self::assertStringContainsString(
            'attachment; filename="' . self::FILENAME . '"',
            $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION],
        );
        self::assertFalse($response->tooLarge);
        self::assertFalse($response->onAnotherNode);
    }

    public function testBehindNginxTheBodyIsEmptyAndTheStoredNameIsRedirected(): void
    {
        file_put_contents($this->file->getPath(), 'x');
        $response = $this->response(self::XACCEL_LOCATION, 'node-b', 'node-a');

        self::assertSame(HttpConstants::HTTP_OK, $response->reply->status);
        self::assertSame('', $response->reply->body);
        self::assertSame('/__hilos_legal_export/export.csv', $response->reply->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT]);
        self::assertSame(self::CONTENT_TYPE, $response->reply->headers[HttpConstants::HEADER_CONTENT_TYPE]);
    }

    public function testWithoutNginxABrowserOnAnotherNodeIsRefusedAndNamed(): void
    {
        file_put_contents($this->file->getPath(), 'x');
        $elsewhere = $this->response('', 'node-b', 'node-a');

        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $elsewhere->reply->status);
        self::assertTrue($elsewhere->onAnotherNode);
        self::assertFalse($elsewhere->tooLarge);
        self::assertSame(HttpConstants::HTTP_OK, $this->response('', 'node-a', 'node-a')->reply->status);
    }

    public function testWithoutNginxAFileOverTheCeilingIsRefusedAndNamed(): void
    {
        file_put_contents($this->file->getPath(), str_repeat('x', FileDownloadResponse::DIRECT_MAX_BYTES));
        self::assertSame(HttpConstants::HTTP_OK, $this->response('')->reply->status);

        file_put_contents($this->file->getPath(), 'x', FILE_APPEND);
        clearstatcache(true, $this->file->getPath());
        $direct = $this->response('');

        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $direct->reply->status);
        self::assertTrue($direct->tooLarge);
        self::assertSame(HttpConstants::HTTP_OK, $this->response(self::XACCEL_LOCATION)->reply->status);
    }

    public function testAMissingFileIsNotFound(): void
    {
        $reply = $this->response(self::XACCEL_LOCATION)->reply;

        self::assertSame(HttpConstants::HTTP_NOT_FOUND, $reply->status);
        self::assertSame('no-store', $reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
    }

    /**
     * @param string $location Internal nginx location, or empty for the daemon's own body
     * @param ?string $originNodeId Node holding the browser's connection, null off a cluster
     * @param ?string $localNodeId Node answering, null off a cluster
     * @return PrivateDownloadResponse Response under test
     */
    private function response(string $location, ?string $originNodeId = null, ?string $localNodeId = null): PrivateDownloadResponse
    {
        return PrivateDownloadResponse::forFile(
            new HttpRequestDTO('request', HttpConstants::METHOD_GET, '/_hilos/legal-acceptances-export', [], 'session', $originNodeId),
            $this->file,
            self::CONTENT_TYPE,
            self::FILENAME,
            $location,
            $localNodeId,
        );
    }
}
