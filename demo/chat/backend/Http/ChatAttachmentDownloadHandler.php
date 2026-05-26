<?php

declare(strict_types=1);

namespace Demo\Chat\Http;

use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Hilos;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Fs\FsException;

/**
 * HTTP GET /chat/attachment — stream published attachment after session check.
 */
final class ChatAttachmentDownloadHandler
{
    private const string DEFAULT_DOWNLOAD_FILENAME = 'file';
    private const string ERROR_NOT_FOUND = 'Not found';
    private const string ERROR_UNAUTHORIZED = 'Unauthorized';
    private const string IMAGE_CONTENT_TYPE_PREFIX = 'image/';
    private const string JSON_ENCODE_FAILED_BODY = '{"error":"json_encode_failed"}';
    private const string JSON_KEY_ERROR = 'error';

    /**
     * Streams a published attachment for an authenticated chat session.
     *
     * @param array{request: array<string, mixed>, params: array<int|string, string>} $args Router handler args
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    public static function handle(array $args): array
    {
        $request = $args['request'];
        $queryParams = self::queryParamsFromRequest($request);
        $attachmentId = (int)($queryParams->getString(ObjectEventAttachment::id) ?? 0);
        if ($attachmentId <= 0) {
            return self::notFound();
        }

        $sessionToken = $queryParams->getString(HttpHeaders::SESSION_TOKEN);
        if ($sessionToken === null || $sessionToken === '') {
            return self::unauthorized();
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        if ($user === null) {
            return self::unauthorized();
        }

        $attachment = Hilos::$db->eventAttachments[$attachmentId] ?? null;
        if ($attachment === null) {
            return self::notFound();
        }

        $publishedFile = $attachment->file;
        if (!$publishedFile->exists()) {
            return self::notFound();
        }

        try {
            $body = $publishedFile->read();
        } catch (FsException) {
            return self::notFound();
        }

        $mime = $attachment->mimeType !== ''
            ? $attachment->mimeType
            : HttpConstants::CONTENT_TYPE_OCTET_STREAM;
        $dispositionFilename = self::contentDispositionFilename($attachment->filename);
        // Browsers often refuse to render <img src="..."> when Content-Disposition is "attachment".
        $dispositionType = str_starts_with(strtolower($mime), self::IMAGE_CONTENT_TYPE_PREFIX)
            ? HttpConstants::CONTENT_DISPOSITION_INLINE
            : HttpConstants::CONTENT_DISPOSITION_ATTACHMENT;

        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [
                HttpConstants::HEADER_CONTENT_TYPE => $mime,
                HttpConstants::HEADER_CONTENT_DISPOSITION => $dispositionType
                    . '; filename="' . $dispositionFilename . '"',
            ],
            HttpConstants::RESPONSE_KEY_BODY => $body,
        ];
    }

    private static function contentDispositionFilename(string $name): string
    {
        $base = basename(str_replace(["\r", "\n", '"'], '', $name));
        if ($base === '') {
            return self::DEFAULT_DOWNLOAD_FILENAME;
        }

        return addcslashes($base, '"\\');
    }

    /**
     * Normalizes query params from the router request payload.
     *
     * @param array<string, mixed> $request Request data
     */
    private static function queryParamsFromRequest(array $request): RequestQueryParams
    {
        $queryParams = $request['queryParams'] ?? null;
        if ($queryParams instanceof RequestQueryParams) {
            return $queryParams;
        }

        return is_array($queryParams)
            ? RequestQueryParams::fromStringMap($queryParams)
            : RequestQueryParams::empty();
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    private static function notFound(): array
    {
        return self::jsonErrorResponse(HttpConstants::HTTP_NOT_FOUND, self::ERROR_NOT_FOUND);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    private static function unauthorized(): array
    {
        return self::jsonErrorResponse(HttpConstants::HTTP_UNAUTHORIZED, self::ERROR_UNAUTHORIZED);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     */
    private static function jsonErrorResponse(int $status, string $message): array
    {
        $body = json_encode(
            [self::JSON_KEY_ERROR => $message],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return [
            HttpConstants::RESPONSE_KEY_STATUS => $status,
            HttpConstants::RESPONSE_KEY_HEADERS => [
                HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
            ],
            HttpConstants::RESPONSE_KEY_BODY => $body !== false ? $body : self::JSON_ENCODE_FAILED_BODY,
        ];
    }
}
