<?php

declare(strict_types=1);

namespace Hilos\Files;

use HashContext;
use Hilos\Core\Exception\LogicException;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\FsPath;

/**
 * The fingerprint of a file's content: sha256 of its bytes, as lowercase hex (HIL-136).
 *
 * Counted on the fly while a file arrives - {@see UploadsAgent} starts one when an upload opens
 * and feeds it every chunk it writes - so the whole file is never read again to learn it. It is
 * kept on the upload row and on the registry row, and a duplicate check compares it there.
 */
final class ContentHash
{
    /** Digest algorithm, as `hash_init()` names it. */
    public const string ALGORITHM = 'sha256';

    /** Length of the fingerprint in hex characters. */
    public const int LENGTH = 64;

    /** What a fingerprint looks like: exactly {@see self::LENGTH} lowercase hex characters. */
    private const string PATTERN = '/\A[0-9a-f]{' . self::LENGTH . '}\z/';

    /** Running digest, or null once the fingerprint is taken. */
    private ?HashContext $context;

    /**
     * @param HashContext $context Running digest of nothing yet
     */
    private function __construct(HashContext $context)
    {
        $this->context = $context;
    }

    /**
     * Starts the fingerprint of a file whose bytes will arrive in pieces.
     *
     * @return self Fingerprint of no byte yet
     */
    public static function start(): self
    {
        return new self(hash_init(self::ALGORITHM));
    }

    /**
     * Fingerprints a whole file on disk.
     *
     * @param string $path Absolute path of the file
     * @return string Lowercase hex fingerprint
     * @throws FileNotFoundException When the file is not there
     * @throws FileReadException When the file cannot be read
     */
    public static function ofFile(string $path): string
    {
        return FsPath::hash(self::ALGORITHM, $path);
    }

    /**
     * @param string $hash Value to judge
     * @return bool Whether the value has the shape of a fingerprint
     */
    public static function isValid(string $hash): bool
    {
        return preg_match(self::PATTERN, $hash) === 1;
    }

    /**
     * Adds the next piece of the file.
     *
     * @param string $bytes Bytes that follow the ones already added
     * @throws LogicException When the fingerprint is already taken
     */
    public function update(string $bytes): void
    {
        hash_update($this->context ?? throw new LogicException('The content fingerprint is already taken'), $bytes);
    }

    /**
     * Takes the fingerprint of every byte added; nothing can be added after.
     *
     * @return string Lowercase hex fingerprint
     * @throws LogicException When the fingerprint is already taken
     */
    public function finish(): string
    {
        $hash = hash_final($this->context ?? throw new LogicException('The content fingerprint is already taken'));
        $this->context = null;

        return $hash;
    }
}
