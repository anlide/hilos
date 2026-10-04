<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\ContentHash;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsTmpFile;

/** Bounded output writer whose digest always describes the bytes on disk. */
final class ImageMetadataSink
{
    private const int FLUSH_BYTES = 1048576;

    private string $buffer = '';
    private ContentHash $hash;

    /** @param FsTmpFile $file Destination in the upload temporary directory */
    public function __construct(private readonly FsTmpFile $file)
    {
        $this->hash = ContentHash::start();
    }

    /**
     * @param string $bytes Next output bytes
     * @throws FileWriteException When the destination cannot be written
     * @throws LogicException When the digest has already been finalized
     */
    public function write(string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $take = min(self::FLUSH_BYTES - strlen($this->buffer), strlen($bytes) - $offset);
            $this->buffer .= substr($bytes, $offset, $take);
            $offset += $take;
            if (strlen($this->buffer) === self::FLUSH_BYTES) {
                $this->flush();
            }
        }
    }

    /**
     * @return string SHA-256 of every written byte
     * @throws FileWriteException When the last buffer cannot be written
     * @throws LogicException When the digest has already been finalized
     */
    public function finish(): string
    {
        $this->flush();

        return $this->hash->finish();
    }

    /**
     * @throws FileWriteException When the destination cannot be written
     * @throws LogicException When the digest has already been finalized
     */
    private function flush(): void
    {
        if ($this->buffer === '') {
            return;
        }
        $this->file->append($this->buffer);
        $this->hash->update($this->buffer);
        $this->buffer = '';
    }
}
