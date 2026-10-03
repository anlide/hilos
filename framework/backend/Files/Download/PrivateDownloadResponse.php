<?php

declare(strict_types=1);

namespace Hilos\Files\Download;

use Hilos\Constants\HttpConstants;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Utils\Helpers\HttpHeaderHelper;

/**
 * Delivers a file its caller already authorized to one session, as a private attachment (HIL-1234).
 *
 * Behind nginx the body is empty and X-Accel-Redirect names the file under the configured internal
 * location; without it the daemon sends the bytes itself, capped by {@see FileDownloadResponse::DIRECT_MAX_BYTES}
 * and only to a browser whose connection this node holds. Who may have the file is the caller's question;
 * this one answers how it travels, and names a transport the operator has to fix in {@see self::$tooLarge}
 * and {@see self::$onAnotherNode}.
 */
final readonly class PrivateDownloadResponse
{
    /**
     * @param HttpReplyDTO $reply HTTP response
     * @param bool $tooLarge Whether direct transport refused the size and the operator needs nginx
     * @param bool $onAnotherNode Whether the browser's connection is on another node and the operator needs nginx
     */
    private function __construct(public HttpReplyDTO $reply, public bool $tooLarge = false, public bool $onAnotherNode = false)
    {
    }

    /**
     * @param HttpRequestDTO $request Request being answered
     * @param FsFile $file Authorized file on disk
     * @param string $contentType Media type of the file
     * @param string $filename Name the browser saves the file under
     * @param string $xAccelLocation Internal nginx location, or empty for direct transport
     * @param ?string $localNodeId This node's id, null off a cluster
     * @return self Private download or refusal
     * @throws FsException When the existing file cannot be measured
     */
    public static function forFile(
        HttpRequestDTO $request,
        FsFile $file,
        string $contentType,
        string $filename,
        string $xAccelLocation,
        ?string $localNodeId,
    ): self {
        if (!$file->exists()) {
            return new self(HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND));
        }
        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE => $contentType,
            HttpConstants::HEADER_CONTENT_DISPOSITION => HttpHeaderHelper::contentDisposition(
                HttpConstants::CONTENT_DISPOSITION_ATTACHMENT,
                $filename,
            ),
            HttpConstants::HEADER_CACHE_CONTROL => 'private, no-store',
            HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS => HttpConstants::X_CONTENT_TYPE_OPTIONS_NOSNIFF,
        ];
        if ($xAccelLocation !== '') {
            $headers[HttpConstants::HEADER_X_ACCEL_REDIRECT] = rtrim($xAccelLocation, '/') . '/' . rawurlencode($file->getFilename());

            return new self(HttpReplyDTO::response($request, HttpConstants::HTTP_OK, $headers, ''));
        }
        if ($request->originNodeId !== null && $request->originNodeId !== $localNodeId) {
            return new self(HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR), onAnotherNode: true);
        }
        if ($file->size() > FileDownloadResponse::DIRECT_MAX_BYTES) {
            return new self(HttpReplyDTO::refusal($request, HttpConstants::HTTP_INTERNAL_ERROR), tooLarge: true);
        }
        try {
            $body = $file->read();
        } catch (FsException) {
            return new self(HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND));
        }

        return new self(HttpReplyDTO::response($request, HttpConstants::HTTP_OK, $headers, $body));
    }
}
