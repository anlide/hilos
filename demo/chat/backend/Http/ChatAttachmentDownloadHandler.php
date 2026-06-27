<?php

declare(strict_types=1);

namespace Demo\Chat\Http;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Constants\CookieNames;
use Demo\Chat\Constants\HttpHeaders;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Hilos;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Fs\FsException;
use Hilos\Utils\Helpers\HttpHeaderHelper;

/**
 * HTTP GET /chat/attachment?id= — serve a published attachment after a cookie
 * session check. Behind nginx (test/prod) the daemon authorizes only and hands
 * byte streaming to the web server via X-Accel-Redirect; in dev (no front web
 * server) it streams the file itself.
 */
final class ChatAttachmentDownloadHandler
{
    private const string DEFAULT_DOWNLOAD_FILENAME = 'file';
    private const string ERROR_NOT_FOUND = 'Not found';
    private const string ERROR_UNAUTHORIZED = 'Unauthorized';
    private const string IMAGE_CONTENT_TYPE_PREFIX = 'image/';
    private const string JSON_ENCODE_FAILED_BODY = '{"error":"json_encode_failed"}';
    private const string JSON_KEY_ERROR = 'error';
    // stored_name is content-unique, so a published file never changes under its id.
    private const string CACHE_CONTROL_VALUE = 'private, max-age=31536000, immutable';

    /**
     * Authorizes a chat session by cookie and serves a published attachment.
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

        $sessionToken = self::sessionTokenFromRequest($request);
        if ($sessionToken === '') {
            return self::unauthorized();
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        if ($user === null) {
            return self::unauthorized();
        }

        // Authorization: this demo chat is global — every signed-in user sees the
        // same stream, so a valid session is sufficient. Per-object authz is
        // intentionally omitted: there are no private messages or rooms to scope
        // an attachment to.
        $attachment = Hilos::$db->eventAttachments[$attachmentId] ?? null;
        if ($attachment === null) {
            return self::notFound();
        }

        $publishedFile = $attachment->file;
        if (!$publishedFile->exists()) {
            return self::notFound();
        }

        $mime = $attachment->mimeType !== ''
            ? $attachment->mimeType
            : HttpConstants::CONTENT_TYPE_OCTET_STREAM;
        // Browsers often refuse to render <img src="..."> when Content-Disposition is "attachment".
        $dispositionType = str_starts_with(strtolower($mime), self::IMAGE_CONTENT_TYPE_PREFIX)
            ? HttpConstants::CONTENT_DISPOSITION_INLINE
            : HttpConstants::CONTENT_DISPOSITION_ATTACHMENT;
        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE => $mime,
            HttpConstants::HEADER_CONTENT_DISPOSITION => $dispositionType
                . '; filename="' . self::contentDispositionFilename($attachment->filename) . '"',
            HttpConstants::HEADER_CACHE_CONTROL => self::CACHE_CONTROL_VALUE,
        ];

        $xAccelLocation = Hilos::$env[ChatEnvConstants::CHAT_FILES_XACCEL_LOCATION];
        if ($xAccelLocation !== '') {
            $headers[HttpHeaders::X_ACCEL_REDIRECT] =
                rtrim($xAccelLocation, '/') . '/' . rawurlencode($attachment->storedName);

            return [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => $headers,
                HttpConstants::RESPONSE_KEY_BODY => '',
            ];
        }

        try {
            $body = $publishedFile->read();
        } catch (FsException) {
            return self::notFound();
        }

        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => $headers,
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
     * Reads the session token from the request's Cookie header.
     *
     * @param array<string, mixed> $request Request data
     * @return string Session token, or empty string when no session cookie is present
     */
    private static function sessionTokenFromRequest(array $request): string
    {
        $headers = $request[HttpConstants::REQUEST_KEY_HEADERS] ?? null;
        if (!is_array($headers)) {
            return '';
        }

        return HttpHeaderHelper::parseCookies($headers)[CookieNames::SESSION_TOKEN] ?? '';
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
