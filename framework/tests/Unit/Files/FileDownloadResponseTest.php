<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files;

use Hilos\Constants\HttpConstants;
use Hilos\Database\Entity\Item\File as EntityFile;
use Hilos\Database\Entity\Item\FileVariant as EntityFileVariant;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Database\Object\Item\FileVariant as ObjectFileVariant;
use Hilos\Database\View\Item\File;
use Hilos\Database\View\Item\FileVariant;
use Hilos\Files\Download\FileDownloadOutcome;
use Hilos\Files\Download\FileDownloadResponse;
use Hilos\Files\FileVisibility;
use Hilos\Files\Storage\FilesStorageInterface;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * How a file its viewer may see is answered (HIL-138): the headers, and both transports.
 *
 * Behind nginx the body is empty and X-Accel-Redirect names the stored file; without it the
 * daemon sends the bytes, up to its ceiling. SVG is never rendered in place - opened on the site,
 * it would run its script there - and every served file carries nosniff.
 */
final class FileDownloadResponseTest extends TestCase
{
    /** Stored name of the fixture file */
    private const string STORED_NAME = '0123456789abcdef0123456789abcdef.png';

    /** Internal nginx location the tests configure */
    private const string XACCEL_LOCATION = '/_files_internal/';

    /** Node the library runs on in the cluster cases */
    private const string THIS_NODE = 'node-a';

    /** Node holding the browser's connection when it is not the library's */
    private const string OTHER_NODE = 'node-b';

    public function testAnImageIsServedInlineWithItsTypeAndNosniff(): void
    {
        $response = $this->serve($this->file('photo.png', 'image/png'), $this->storage("\x89PNG"), '');

        $this->assertSame(FileDownloadOutcome::SERVED, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_OK, $response->reply->status);
        $this->assertSame("\x89PNG", $response->reply->body);
        $this->assertSame('image/png', $response->reply->headers[HttpConstants::HEADER_CONTENT_TYPE]);
        $this->assertSame(
            "inline; filename=\"photo.png\"; filename*=UTF-8''photo.png",
            $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION],
        );
        $this->assertSame('nosniff', $response->reply->headers[HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS]);
    }

    public function testAnSvgIsServedAsADownload(): void
    {
        $response = $this->serve($this->file('logo.svg', 'image/svg+xml'), $this->storage('<svg/>'), '');

        $this->assertStringStartsWith('attachment;', $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION]);
    }

    public function testADocumentIsServedAsADownload(): void
    {
        $response = $this->serve($this->file('report.pdf', 'application/pdf'), $this->storage('%PDF'), '');

        $this->assertStringStartsWith('attachment;', $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION]);
    }

    public function testACyrillicNameKeepsItselfInTheUtf8FormAndAnAsciiFallback(): void
    {
        $response = $this->serve($this->file('Звіт "2026".pdf', 'application/pdf'), $this->storage('%PDF'), '');

        $this->assertSame(
            "attachment; filename=\"____ 2026.pdf\"; filename*=UTF-8''%D0%97%D0%B2%D1%96%D1%82%20%222026%22.pdf",
            $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION],
        );
    }

    public function testAPublicFileIsCachedByAnyoneAndAPrivateOneByTheBrowserAlone(): void
    {
        $public = $this->serve($this->file('a.png', 'image/png', FileVisibility::PUBLIC), $this->storage('x'), '');
        $owner = $this->serve($this->file('a.png', 'image/png', FileVisibility::OWNER), $this->storage('x'), '');
        $signedIn = $this->serve($this->file('a.png', 'image/png', FileVisibility::AUTHENTICATED), $this->storage('x'), '');

        $this->assertSame('public, max-age=31536000, immutable', $public->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        $this->assertSame('private, max-age=31536000, immutable', $owner->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        $this->assertSame('private, max-age=31536000, immutable', $signedIn->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
    }

    public function testBehindNginxTheBodyIsEmptyAndXAccelNamesTheStoredFile(): void
    {
        $storage = $this->storage('bytes nginx sends');
        $response = $this->serve($this->file('photo.png', 'image/png'), $storage, self::XACCEL_LOCATION);

        $this->assertSame(HttpConstants::HTTP_OK, $response->reply->status);
        $this->assertSame('', $response->reply->body);
        $this->assertSame(
            '/_files_internal/' . self::STORED_NAME,
            $response->reply->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT],
        );
        $this->assertSame('nosniff', $response->reply->headers[HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS]);
        $this->assertSame(0, $storage->reads, 'behind nginx the daemon never reads the bytes');
    }

    public function testTheStoredNameIsEncodedInTheXAccelPath(): void
    {
        $file = $this->file('photo.png', 'image/png', FileVisibility::OWNER, 'a b%.png');
        $response = $this->serve($file, $this->storage('x', 'a b%.png'), '/internal');

        $this->assertSame('/internal/a%20b%25.png', $response->reply->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT]);
    }

    public function testAFileOfExactlyTheCeilingIsSentByTheDaemon(): void
    {
        $response = $this->serve(
            $this->file('big.png', 'image/png'),
            $this->storage('x', size: FileDownloadResponse::DIRECT_MAX_BYTES),
            '',
        );

        $this->assertSame(FileDownloadOutcome::SERVED, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_OK, $response->reply->status);
    }

    public function testAFileAboveTheCeilingWithoutNginxIsRefusedWith500(): void
    {
        $storage = $this->storage('x', size: FileDownloadResponse::DIRECT_MAX_BYTES + 1);
        $response = $this->serve($this->file('big.png', 'image/png'), $storage, '');

        $this->assertSame(FileDownloadOutcome::TOO_LARGE_TO_SEND_DIRECTLY, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $response->reply->status);
        $this->assertSame(FileDownloadResponse::DIRECT_MAX_BYTES + 1, $response->size);
        $this->assertSame(0, $storage->reads, 'a file above the ceiling is not read at all');
    }

    public function testAFileAboveTheCeilingIsStillServedBehindNginx(): void
    {
        $storage = $this->storage('x', size: FileDownloadResponse::DIRECT_MAX_BYTES + 1);
        $response = $this->serve($this->file('big.png', 'image/png'), $storage, self::XACCEL_LOCATION);

        $this->assertSame(HttpConstants::HTTP_OK, $response->reply->status);
    }

    public function testAFileForABrowserOnAnotherNodeWithoutNginxIsRefusedWith500(): void
    {
        $storage = $this->storage('x');
        $response = $this->serve($this->file('photo.png', 'image/png'), $storage, '', self::OTHER_NODE, self::THIS_NODE);

        $this->assertSame(FileDownloadOutcome::CONNECTION_ON_ANOTHER_NODE, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $response->reply->status);
        $this->assertSame(HttpConstants::CACHE_CONTROL_NO_STORE, $response->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        $this->assertSame(0, $storage->reads, 'a file for another node is not read at all');
    }

    /** The node, not the size, is what the operator has to fix: nginx serves a file of any size. */
    public function testAFileAboveTheCeilingForABrowserOnAnotherNodeIsRefusedForTheNode(): void
    {
        $response = $this->serve(
            $this->file('big.png', 'image/png'),
            $this->storage('x', size: FileDownloadResponse::DIRECT_MAX_BYTES + 1),
            '',
            self::OTHER_NODE,
            self::THIS_NODE,
        );

        $this->assertSame(FileDownloadOutcome::CONNECTION_ON_ANOTHER_NODE, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $response->reply->status);
    }

    public function testBehindNginxAFileIsHandedToXAccelForABrowserOnAnotherNode(): void
    {
        $response = $this->serve(
            $this->file('photo.png', 'image/png'),
            $this->storage('x'),
            self::XACCEL_LOCATION,
            self::OTHER_NODE,
            self::THIS_NODE,
        );

        $this->assertSame(FileDownloadOutcome::SERVED, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_OK, $response->reply->status);
        $this->assertSame('', $response->reply->body);
        $this->assertSame('/_files_internal/' . self::STORED_NAME, $response->reply->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT]);
    }

    public function testAFileForABrowserOnThisNodeIsSentByTheDaemon(): void
    {
        $response = $this->serve($this->file('photo.png', 'image/png'), $this->storage("\x89PNG"), '', self::THIS_NODE, self::THIS_NODE);

        $this->assertSame(FileDownloadOutcome::SERVED, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_OK, $response->reply->status);
        $this->assertSame("\x89PNG", $response->reply->body);
    }

    public function testAVariantAndItsFallbackForABrowserOnAnotherNodeWithoutNginxAreRefused(): void
    {
        $request = new HttpRequestDTO(
            str_repeat('c', 32),
            HttpConstants::METHOD_GET,
            '/_hilos/file',
            ['id' => '1', 'variant' => 'thumb'],
            null,
            self::OTHER_NODE,
        );
        $file = $this->file('photo.jpg', 'image/jpeg');

        $variant = FileDownloadResponse::forVariant(
            $request, $file, $this->variant(), $this->storage('webp copy', 'copy.webp'), '', self::THIS_NODE,
        );
        $fallback = FileDownloadResponse::forFallback($request, $file, $this->storage('original'), '', self::THIS_NODE);

        $this->assertSame(FileDownloadOutcome::CONNECTION_ON_ANOTHER_NODE, $variant->outcome);
        $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $variant->reply->status);
        $this->assertSame(FileDownloadOutcome::CONNECTION_ON_ANOTHER_NODE, $fallback->outcome);
        $this->assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $fallback->reply->status);
    }

    public function testARowWithNoFileOnDiskIsRefusedWith404(): void
    {
        $response = $this->serve($this->file('gone.png', 'image/png'), new FileDownloadResponseTestStorage([]), '');

        $this->assertSame(FileDownloadOutcome::MISSING_ON_DISK, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $response->reply->status);
        $this->assertSame(HttpConstants::CACHE_CONTROL_NO_STORE, $response->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
    }

    public function testAFileThatCannotBeReadIsRefusedWith404(): void
    {
        $storage = $this->storage('x');
        $storage->readFails = true;
        $response = $this->serve($this->file('locked.png', 'image/png'), $storage, '');

        $this->assertSame(FileDownloadOutcome::UNREADABLE, $response->outcome);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $response->reply->status);
    }

    /** Copies use their own bytes, MIME type and extension, and inherit the original's cache visibility. */
    public function testAVariantIsServedDirectlyOrThroughNginx(): void
    {
        $request = new HttpRequestDTO(str_repeat('c', 32), 'GET', '/_hilos/file', ['id' => '1', 'variant' => 'thumb'], null, null);
        foreach ([FileVisibility::PUBLIC, FileVisibility::OWNER, FileVisibility::AUTHENTICATED] as $visibility) {
            foreach (['', self::XACCEL_LOCATION] as $xAccelLocation) {
                $storage = $this->storage('webp copy', 'copy.webp');
                $response = FileDownloadResponse::forVariant(
                    $request, $this->file('photo.jpg', 'image/jpeg', $visibility), $this->variant(), $storage, $xAccelLocation, null,
                );
                self::assertSame(200, $response->reply->status);
                self::assertSame('image/webp', $response->reply->headers[HttpConstants::HEADER_CONTENT_TYPE]);
                self::assertSame("inline; filename=\"photo.webp\"; filename*=UTF-8''photo.webp",
                    $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION]);
                self::assertSame('nosniff', $response->reply->headers[HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS]);
                self::assertSame(($visibility === FileVisibility::PUBLIC ? 'public' : 'private') . ', max-age=31536000, immutable',
                    $response->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
                if ($xAccelLocation === '') {
                    self::assertSame('webp copy', $response->reply->body);
                    self::assertSame(1, $storage->reads);
                } else {
                    self::assertSame('/_files_internal/copy.webp', $response->reply->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT]);
                    self::assertSame('', $response->reply->body);
                    self::assertSame(0, $storage->reads);
                }
            }
        }
    }

    /** Original responses used as fallbacks have a one-hour cache, including behind nginx. */
    public function testFallbackKeepsTheOriginalHeadersButShortensTheCache(): void
    {
        $request = new HttpRequestDTO(str_repeat('c', 32), 'GET', '/_hilos/file', ['id' => '1', 'variant' => 'thumb'], null, null);
        foreach ([FileVisibility::PUBLIC, FileVisibility::OWNER, FileVisibility::AUTHENTICATED] as $visibility) {
            foreach (['', self::XACCEL_LOCATION] as $xAccelLocation) {
                $response = FileDownloadResponse::forFallback(
                    $request, $this->file('photo.jpg', 'image/jpeg', $visibility), $this->storage('original'), $xAccelLocation, null,
                );
                self::assertSame(200, $response->reply->status);
                self::assertSame('image/jpeg', $response->reply->headers[HttpConstants::HEADER_CONTENT_TYPE]);
                self::assertSame("inline; filename=\"photo.jpg\"; filename*=UTF-8''photo.jpg",
                    $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION]);
                self::assertSame(($visibility === FileVisibility::PUBLIC ? 'public' : 'private') . ', max-age=3600',
                    $response->reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
            }
        }
    }

    /** A name without a stem still gets a usable download filename. */
    public function testAVariantOfADotfileHasAFallbackFilename(): void
    {
        $request = new HttpRequestDTO(str_repeat('c', 32), 'GET', '/_hilos/file', ['id' => '1'], null, null);
        $response = FileDownloadResponse::forVariant(
            $request, $this->file('.jpg', 'image/jpeg'), $this->variant(), $this->storage('copy', 'copy.webp'), '', null,
        );
        self::assertSame("inline; filename=\"file.webp\"; filename*=UTF-8''file.webp",
            $response->reply->headers[HttpConstants::HEADER_CONTENT_DISPOSITION]);
    }

    /** @return FileVariant Registered WEBP copy fixture */
    private function variant(): FileVariant
    {
        return new FileVariant(ObjectFileVariant::fromEntity(EntityFileVariant::fromRow([
            EntityFileVariant::id => 2,
            EntityFileVariant::file_id => 1,
            EntityFileVariant::variant => 'thumb',
            EntityFileVariant::signature => '1234abcd',
            EntityFileVariant::stored_name => 'copy.webp',
            EntityFileVariant::mime_type => 'image/webp',
            EntityFileVariant::size => 9,
            EntityFileVariant::created_at => '2026-09-27 12:00:00',
        ])));
    }

    /**
     * @param File $file Row of the file served
     * @param FilesStorageInterface $storage Storage the file is kept in
     * @param string $xAccelLocation Internal nginx location, empty for none
     * @param ?string $originNodeId Node holding the browser's connection, null off a cluster
     * @param ?string $localNodeId Node the library runs on, null off a cluster
     * @return FileDownloadResponse Response built
     */
    private function serve(
        File $file,
        FilesStorageInterface $storage,
        string $xAccelLocation,
        ?string $originNodeId = null,
        ?string $localNodeId = null,
    ): FileDownloadResponse {
        $request = new HttpRequestDTO(
            str_repeat('c', 32),
            HttpConstants::METHOD_GET,
            '/_hilos/file',
            ['id' => '1'],
            null,
            $originNodeId,
        );

        return FileDownloadResponse::forFile($request, $file, $storage, $xAccelLocation, $localNodeId);
    }

    /**
     * @param string $filename Name the file was uploaded under
     * @param string $mimeType Type the file was uploaded with
     * @param FileVisibility $visibility Visibility of the row
     * @param string $storedName Name the file is kept under
     * @return File Registry row
     */
    private function file(
        string $filename,
        string $mimeType,
        FileVisibility $visibility = FileVisibility::OWNER,
        string $storedName = self::STORED_NAME,
    ): File {
        return new File(ObjectFile::fromEntity(EntityFile::fromRow([
            EntityFile::id => 1,
            EntityFile::stored_name => $storedName,
            EntityFile::filename => $filename,
            EntityFile::mime_type => $mimeType,
            EntityFile::size => 1,
            EntityFile::content_hash => str_repeat('0', 64),
            EntityFile::owner_user_id => 7,
            EntityFile::visibility => $visibility->value,
            EntityFile::bound => 1,
            EntityFile::created_at => '2026-09-26 12:00:00',
        ])));
    }

    /**
     * @param string $bytes Bytes kept under the stored name
     * @param string $storedName Name the bytes are kept under
     * @param ?int $size Size the storage reports, the length of the bytes when null
     * @return FileDownloadResponseTestStorage Storage holding one file
     */
    private function storage(string $bytes, string $storedName = self::STORED_NAME, ?int $size = null): FileDownloadResponseTestStorage
    {
        return new FileDownloadResponseTestStorage([$storedName => [$bytes, $size ?? strlen($bytes)]]);
    }
}

/**
 * Storage double that keeps files in memory and counts its reads.
 */
final class FileDownloadResponseTestStorage implements FilesStorageInterface
{
    /** @var int Reads asked of the storage */
    public int $reads = 0;

    /** @var bool Whether a read fails as an unreadable file */
    public bool $readFails = false;

    /**
     * @param array<string, array{string, int}> $files Bytes and reported size, by stored name
     */
    public function __construct(private readonly array $files)
    {
    }

    public function storeFromTmp(string $storedName, string $tmpIndex): void
    {
    }

    public function delete(string $storedName): void
    {
    }

    /**
     * @param string $storedName Name the file is kept under
     * @return ?int Reported size, or null when nothing is kept under the name
     */
    public function size(string $storedName): ?int
    {
        return isset($this->files[$storedName]) ? $this->files[$storedName][1] : null;
    }

    /**
     * @param string $storedName Name the file is kept under
     * @return string Kept bytes
     * @throws FileNotFoundException When nothing is kept under the name
     * @throws FileReadException When the case asks the read to fail
     */
    public function read(string $storedName): string
    {
        $this->reads++;
        if ($this->readFails) {
            throw new FileReadException("Cannot read file: {$storedName}");
        }

        return $this->files[$storedName][0] ?? throw new FileNotFoundException("File not found: {$storedName}");
    }
}
