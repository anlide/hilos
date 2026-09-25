<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * One signed chunk of an upload as it arrives in a frame_binary frame (HIL-135).
 *
 * The frame is `[1 byte L][L bytes clientUploadId][file bytes]`: every chunk names the upload
 * it belongs to, so several uploads of one connection run at once without any of them waiting
 * its turn. The signature is the whole of the addressing - the frame carries nothing else - and
 * a frame whose signature cannot be read is not a chunk of anything.
 */
final readonly class UploadFrame
{
    /** Longest upload id a client may give; one length byte could carry more, the wire does not. */
    public const int MAX_ID_LENGTH = 64;

    /** Id alphabet: letters, digits, dash and underscore - a UUID fits, a separator never does. */
    private const string ID_PATTERN = '/\A[A-Za-z0-9_-]{1,' . self::MAX_ID_LENGTH . '}\z/';

    /** Bytes the length prefix takes. */
    private const int LENGTH_PREFIX_BYTES = 1;

    /**
     * @param string $clientUploadId Upload the chunk belongs to
     * @param string $bytes File bytes of the chunk, possibly empty
     */
    public function __construct(
        public string $clientUploadId,
        public string $bytes,
    ) {
    }

    /**
     * Whether a string may name an upload on the wire.
     *
     * @param string $id Candidate upload id
     * @return bool Whether it is 1 to {@see self::MAX_ID_LENGTH} characters of the id alphabet
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Reads the signature off one binary frame.
     *
     * @param string $payload Raw frame_binary payload
     * @return ?self The chunk, or null when the frame carries no readable signature
     */
    public static function parse(string $payload): ?self
    {
        if ($payload === '') {
            return null;
        }

        $idLength = ord($payload[0]);
        if ($idLength === 0 || $idLength > self::MAX_ID_LENGTH || strlen($payload) < self::LENGTH_PREFIX_BYTES + $idLength) {
            return null;
        }

        $clientUploadId = substr($payload, self::LENGTH_PREFIX_BYTES, $idLength);
        if (!self::isValidId($clientUploadId)) {
            return null;
        }

        return new self($clientUploadId, substr($payload, self::LENGTH_PREFIX_BYTES + $idLength));
    }
}
