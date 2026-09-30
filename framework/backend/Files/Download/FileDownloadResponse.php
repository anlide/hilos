<?php

declare(strict_types=1);

namespace Hilos\Files\Download;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Database\View\Item\File;
use Hilos\Database\View\Item\FileVariant;
use Hilos\Files\FileVisibility;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\FilesStorageInterface;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\HilosException;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Utils\Helpers\HttpHeaderHelper;

/**
 * The response serving one registry file the viewer may see (HIL-138).
 *
 * Built apart from the library agent so the headers and both transports can be tested without
 * a daemon: {@see AbstractFilesLibraryAgent} decides WHETHER to serve, this decides HOW. Behind
 * nginx the body is empty and X-Accel-Redirect names the file under the configured internal
 * location; without it the daemon sends the bytes itself, which is the transport of a dev stack:
 * capped by {@see self::DIRECT_MAX_BYTES}, and only to a browser whose connection this node holds.
 *
 * What it answers is read off {@see self::$reply}, and {@see self::$outcome} names what went wrong
 * when it is a refusal, so the agent can say it in its own journal.
 */
final readonly class FileDownloadResponse
{
    /**
     * Largest file the daemon sends in the body of its own response, in bytes.
     *
     * The body rides the reply frame base64-encoded, 4/3 of its size, through the master of this
     * node and never over the peer link: a browser on another node gets
     * {@see FileDownloadOutcome::CONNECTION_ON_ANOTHER_NODE} instead. 4 MiB keeps the frame the
     * master decodes small. Anything larger is nginx's to send.
     */
    public const int DIRECT_MAX_BYTES = 4194304;

    /** @var string Cache-Control of a public file: its bytes never change under its id */
    private const string CACHE_CONTROL_PUBLIC = 'public, max-age=31536000, immutable';

    /** @var string Cache-Control of a file only some viewers may see: kept by the browser alone */
    private const string CACHE_CONTROL_PRIVATE = 'private, max-age=31536000, immutable';

    private const string CACHE_CONTROL_FALLBACK_PUBLIC = 'public, max-age=3600';
    private const string CACHE_CONTROL_FALLBACK_PRIVATE = 'private, max-age=3600';

    /** @var string Prefix of the MIME types a browser may render in place */
    private const string IMAGE_MIME_PREFIX = 'image/';

    /** @var string The one image type served as a download: an SVG opened on the site runs its script there */
    private const string SVG_MIME_TYPE = 'image/svg+xml';

    /** @var string Separator between the X-Accel location and the stored name */
    private const string PATH_SEPARATOR = '/';

    /**
     * @param HttpReplyDTO $reply What the browser is answered
     * @param FileDownloadOutcome $outcome Served, or why not
     * @param ?int $size Size of the file in bytes, or null when nothing was on disk
     */
    private function __construct(
        public HttpReplyDTO $reply,
        public FileDownloadOutcome $outcome,
        public ?int $size,
    ) {
    }

    /**
     * Builds the response serving a file its viewer may see.
     *
     * @param HttpRequestDTO $request Request being answered
     * @param File $file Registry row of the file
     * @param FilesStorageInterface $storage Where the file is kept
     * @param string $xAccelLocation Internal nginx location of the files directory, empty when the
     *     daemon sends the bytes itself ({@see EnvConstants::HILOS_FILES_XACCEL_LOCATION})
     * @param ?string $localNodeId This node's id, null off a cluster
     * @return self Response, or the refusal that stands in for it
     * @throws FsException When the storage itself cannot be reached
     * @throws HilosException When the file row refuses a field it is read for
     */
    public static function forFile(
        HttpRequestDTO $request,
        File $file,
        FilesStorageInterface $storage,
        string $xAccelLocation,
        ?string $localNodeId,
    ): self {
        return self::build($request, $file->storedName, $file->mimeType, $file->filename,
            $file->visibility === FileVisibility::PUBLIC ? self::CACHE_CONTROL_PUBLIC : self::CACHE_CONTROL_PRIVATE,
            $storage, $xAccelLocation, $localNodeId);
    }

    /**
     * @param HttpRequestDTO $request Request being answered
     * @param File $file Original row, whose visibility and filename the copy inherits
     * @param FileVariant $variant Registered copy to serve
     * @param FilesStorageInterface $storage Where the copy is kept
     * @param string $xAccelLocation Internal nginx location, empty for a direct reply
     * @param ?string $localNodeId This node's id, null off a cluster
     * @return self Copy response or its storage refusal
     * @throws FsException When the storage cannot be reached
     */
    public static function forVariant(
        HttpRequestDTO $request,
        File $file,
        FileVariant $variant,
        FilesStorageInterface $storage,
        string $xAccelLocation,
        ?string $localNodeId,
    ): self {
        $basename = pathinfo($file->filename, PATHINFO_FILENAME);

        return self::build($request, $variant->storedName, $variant->mimeType,
            ($basename === '' ? 'file' : $basename) . FsFile::extensionForMime($variant->mimeType),
            $file->visibility === FileVisibility::PUBLIC ? self::CACHE_CONTROL_PUBLIC : self::CACHE_CONTROL_PRIVATE,
            $storage, $xAccelLocation, $localNodeId);
    }

    /**
     * Revisit a failed rendering after an hour, rather than caching the original for a year.
     *
     * @param HttpRequestDTO $request Request being answered
     * @param File $file Original to send in place of its unavailable variant
     * @param FilesStorageInterface $storage Where the original is kept
     * @param string $xAccelLocation Internal nginx location, empty for a direct reply
     * @param ?string $localNodeId This node's id, null off a cluster
     * @return self Original response with a short cache lifetime, or its storage refusal
     * @throws FsException When the storage cannot be reached
     */
    public static function forFallback(
        HttpRequestDTO $request,
        File $file,
        FilesStorageInterface $storage,
        string $xAccelLocation,
        ?string $localNodeId,
    ): self {
        return self::build($request, $file->storedName, $file->mimeType, $file->filename,
            $file->visibility === FileVisibility::PUBLIC ? self::CACHE_CONTROL_FALLBACK_PUBLIC : self::CACHE_CONTROL_FALLBACK_PRIVATE,
            $storage, $xAccelLocation, $localNodeId);
    }

    /**
     * @param HttpRequestDTO $request Request being answered
     * @param string $storedName File to read from storage
     * @param string $mimeType Encoded MIME type
     * @param string $filename Download filename
     * @param string $cacheControl Cache policy selected by the response kind
     * @param FilesStorageInterface $storage Where the bytes are kept
     * @param string $xAccelLocation Internal nginx location, empty for direct transport
     * @param ?string $localNodeId This node's id, null off a cluster
     * @return self Served response or the precise storage refusal
     * @throws FsException When the storage cannot be reached
     */
    private static function build(
        HttpRequestDTO $request,
        string $storedName,
        string $mimeType,
        string $filename,
        string $cacheControl,
        FilesStorageInterface $storage,
        string $xAccelLocation,
        ?string $localNodeId,
    ): self {
        $size = $storage->size($storedName);
        if ($size === null) {
            return new self(
                HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND),
                FileDownloadOutcome::MISSING_ON_DISK,
                null,
            );
        }

        $inline = str_starts_with($mimeType, self::IMAGE_MIME_PREFIX) && $mimeType !== self::SVG_MIME_TYPE;
        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE => $mimeType,
            HttpConstants::HEADER_CONTENT_DISPOSITION => HttpHeaderHelper::contentDisposition(
                $inline ? HttpConstants::CONTENT_DISPOSITION_INLINE : HttpConstants::CONTENT_DISPOSITION_ATTACHMENT,
                $filename,
            ),
            HttpConstants::HEADER_CACHE_CONTROL => $cacheControl,
            HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS => HttpConstants::X_CONTENT_TYPE_OPTIONS_NOSNIFF,
        ];

        if ($xAccelLocation !== '') {
            $headers[HttpConstants::HEADER_X_ACCEL_REDIRECT] = rtrim($xAccelLocation, self::PATH_SEPARATOR)
                . self::PATH_SEPARATOR . rawurlencode($storedName);

            return new self(
                HttpReplyDTO::response($request, HttpConstants::HTTP_OK, $headers, ''),
                FileDownloadOutcome::SERVED,
                $size,
            );
        }

        if ($request->originNodeId !== null && $request->originNodeId !== $localNodeId) {
            return new self(
                HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR),
                FileDownloadOutcome::CONNECTION_ON_ANOTHER_NODE,
                $size,
            );
        }

        if ($size > self::DIRECT_MAX_BYTES) {
            return new self(
                HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR),
                FileDownloadOutcome::TOO_LARGE_TO_SEND_DIRECTLY,
                $size,
            );
        }

        try {
            $body = $storage->read($storedName);
        } catch (FsException) {
            return new self(
                HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND),
                FileDownloadOutcome::UNREADABLE,
                $size,
            );
        }

        return new self(HttpReplyDTO::response($request, HttpConstants::HTTP_OK, $headers, $body), FileDownloadOutcome::SERVED, $size);
    }
}
