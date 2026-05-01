<?php

declare(strict_types=1);

namespace Demo\Chat\Http;

use Demo\Chat\Hilos;
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
        $attachmentId = isset($queryParams['id']) ? (int)$queryParams['id'] : 0;
        if ($attachmentId <= 0) {
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
        } catch (\Hilos\Fs\FsException) {
            return self::notFound();
        }

        $mime = $attachment->mimeType !== '' ? $attachment->mimeType : 'application/octet-stream';
        $disp = self::contentDispositionFilename($attachment->filename);
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
