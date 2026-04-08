<?php

declare(strict_types=1);

namespace Demo\Chat\Http;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Hilos;
use Demo\Chat\Utils\ChatAttachmentStorage;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\HilosHttpHeaders;

/**
 * HTTP GET /chat/attachment — stream published attachment after session check.
 */
final class ChatAttachmentDownloadHandler
{
    /**
     * @param array{request: array<string, mixed>, params: array<int, string>} $args
     * @return array<string, mixed>
     */
    public static function handle(array $args): array
    {
        $request = $args['request'];
        $queryParams = is_array($request['queryParams'] ?? null) ? $request['queryParams'] : [];
        $token = isset($queryParams['token']) ? (string)$queryParams['token'] : '';
        if ($token === '' || strlen($token) > 64) {
            return self::notFound();
        }

        $sessionToken = $queryParams[HilosHttpHeaders::HILOS_SESSION_TOKEN] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '') {
            return self::unauthorized();
        }

        $user = Hilos::$db->users->findBySession($sessionToken);
        if ($user === null) {
            return self::unauthorized();
        }

        $match = self::findPublishedFileMetaByToken($token);
        if ($match === null) {
            return self::notFound();
        }

        $path = ChatAttachmentStorage::publishedPathForBasename($match['storedName']);
        if (!is_file($path) || !is_readable($path)) {
            return self::notFound();
        }

        $body = file_get_contents($path);
        if ($body === false) {
            return self::notFound();
        }

        $mime = $match['mimeType'] !== '' ? $match['mimeType'] : 'application/octet-stream';
        $disp = self::contentDispositionFilename($match['originalFilename']);
        // Browsers often refuse to render <img src="..."> when Content-Disposition is "attachment".
        $dispositionType = str_starts_with(strtolower($mime), 'image/') ? 'inline' : 'attachment';

        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [
                HttpConstants::HEADER_CONTENT_TYPE => $mime,
                'Content-Disposition' => $dispositionType . '; filename="' . $disp . '"',
            ],
            HttpConstants::RESPONSE_KEY_BODY => $body,
        ];
    }

    /**
     * @return ?array{storedName: string, originalFilename: string, mimeType: string}
     */
    private static function findPublishedFileMetaByToken(string $token): ?array
    {
        foreach (Hilos::$db->events as $event) {
            if ($event->type !== ChatEventType::FILE_SHARED->value) {
                continue;
            }
            $raw = $event->data;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $d = json_decode($raw, true);
            if (!is_array($d)) {
                continue;
            }
            $dt = $d['downloadToken'] ?? '';
            if (!is_string($dt) || $dt !== $token) {
                continue;
            }
            $stored = $d['storedName'] ?? '';
            if (!is_string($stored) || $stored === '') {
                continue;
            }
            $orig = $d['originalFilename'] ?? $d['filename'] ?? 'file';
            $mime = $d['mimeType'] ?? 'application/octet-stream';

            return [
                'storedName' => basename($stored),
                'originalFilename' => is_string($orig) ? $orig : 'file',
                'mimeType' => is_string($mime) ? $mime : 'application/octet-stream',
            ];
        }

        return null;
    }

    private static function contentDispositionFilename(string $name): string
    {
        $base = basename(str_replace(["\r", "\n", '"'], '', $name));
        if ($base === '') {
            return 'file';
        }

        return addcslashes($base, '"\\');
    }

    /**
     * @return array<string, mixed>
     */
    private static function notFound(): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_NOT_FOUND,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => json_encode(['error' => 'Not found']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unauthorized(): array
    {
        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_UNAUTHORIZED,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => json_encode(['error' => 'Unauthorized']),
        ];
    }
}
