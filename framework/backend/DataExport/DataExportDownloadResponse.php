<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Files\Download\FileDownloadResponse;
use Hilos\Fs\FsException;
use Hilos\Fs\FsFile;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Hilos\Utils\Helpers\TimeHelper;

/** Delivers an authorized archive through nginx or a bounded direct reply. */
final readonly class DataExportDownloadResponse
{
    /**
     * @param HttpReplyDTO $reply HTTP response
     * @param bool $tooLarge Whether direct transport refused the size and the operator needs nginx
     */
    private function __construct(public HttpReplyDTO $reply, public bool $tooLarge = false)
    {
    }

    /**
     * @param HttpRequestDTO $request Request being answered
     * @param FsFile $file Authorized archive on disk
     * @param string $finishedAt Archive readiness date in SQL form
     * @param string $xAccelLocation Internal nginx location, or empty for direct transport
     * @return self Private download or refusal
     * @throws FsException When the existing file cannot be measured
     */
    public static function forFile(HttpRequestDTO $request, FsFile $file, string $finishedAt, string $xAccelLocation): self
    {
        if (!$file->exists()) {
            return new self(HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND));
        }
        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE => 'application/zip',
            HttpConstants::HEADER_CONTENT_DISPOSITION => HttpHeaderHelper::contentDisposition(
                HttpConstants::CONTENT_DISPOSITION_ATTACHMENT,
                'your-data-' . gmdate('Y-m-d', intdiv(TimeHelper::sqlToMs($finishedAt), TimeConstants::MS_PER_SECOND)) . '.zip',
            ),
            HttpConstants::HEADER_CACHE_CONTROL => 'private, no-store',
            HttpConstants::HEADER_X_CONTENT_TYPE_OPTIONS => HttpConstants::X_CONTENT_TYPE_OPTIONS_NOSNIFF,
        ];
        if ($xAccelLocation !== '') {
            $headers[HttpConstants::HEADER_X_ACCEL_REDIRECT] = rtrim($xAccelLocation, '/') . '/' . rawurlencode($file->getFilename());

            return new self(HttpReplyDTO::response($request, HttpConstants::HTTP_OK, $headers, ''));
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
