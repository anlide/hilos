<?php

declare(strict_types=1);

namespace Hilos\Files\Metadata;

use Hilos\Core\Exception\LogicException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/** Buffered descriptor reader shared by the three container parsers. */
final class ImageMetadataReader
{
    private const int BUFFER_BYTES = 65536;

    private string $buffer = '';
    private int $bufferOffset = 0;
    private int $position = 0;
    private int $size;

    /**
     * @param resource $input Open descriptor owned by FsPath::readWith()
     * @throws FileReadException When the descriptor cannot be measured
     */
    public function __construct(private readonly mixed $input)
    {
        $stat = fstat($input);
        if ($stat === false) {
            throw new FileReadException('Cannot measure picture input');
        }
        $this->size = $stat['size'];
    }

    /** @return int Total input bytes */
    public function size(): int
    {
        return $this->size;
    }

    /** @return int Next unread byte offset */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * @param int $offset Absolute byte offset
     * @throws FileReadException When seeking fails
     */
    public function seek(int $offset): void
    {
        if (fseek($this->input, $offset) !== 0) {
            throw new FileReadException('Cannot seek picture input');
        }
        $this->position = $offset;
        $this->buffer = '';
        $this->bufferOffset = 0;
    }

    /**
     * @return ?string One byte, or null at end of input
     * @throws FileReadException When the read fails
     */
    public function byte(): ?string
    {
        if ($this->position >= $this->size) {
            return null;
        }
        $this->fill();
        $byte = $this->buffer[$this->bufferOffset++];
        $this->position++;

        return $byte;
    }

    /**
     * @param int $length Number of bytes required
     * @return string Exactly that many bytes
     * @throws ImageMetadataException When the container is truncated
     * @throws FileReadException When the read fails
     */
    public function read(int $length): string
    {
        if ($length < 0 || $length > $this->size - $this->position) {
            throw new ImageMetadataException('Truncated picture container');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->fill();
            $take = min($length - strlen($bytes), strlen($this->buffer) - $this->bufferOffset);
            $bytes .= substr($this->buffer, $this->bufferOffset, $take);
            $this->bufferOffset += $take;
            $this->position += $take;
        }

        return $bytes;
    }

    /**
     * @param int $length Number of bytes to pass through
     * @param ImageMetadataSink $sink Output writer
     * @throws ImageMetadataException When the container is truncated
     * @throws FileReadException When reading fails
     * @throws FileWriteException When writing fails
     * @throws LogicException When the output digest has already been finalized
     */
    public function copy(int $length, ImageMetadataSink $sink): void
    {
        if ($length < 0 || $length > $this->size - $this->position) {
            throw new ImageMetadataException('Truncated picture container');
        }
        while ($length > 0) {
            $take = min($length, self::BUFFER_BYTES);
            $sink->write($this->read($take));
            $length -= $take;
        }
    }

    /**
     * @param int $length Number of bytes to skip
     * @throws ImageMetadataException When the container is truncated
     * @throws FileReadException When seeking fails
     */
    public function skip(int $length): void
    {
        if ($length < 0 || $length > $this->size - $this->position) {
            throw new ImageMetadataException('Truncated picture container');
        }
        $this->seek($this->position + $length);
    }

    /** @throws FileReadException When the descriptor fails before its reported end */
    private function fill(): void
    {
        if ($this->bufferOffset < strlen($this->buffer)) {
            return;
        }
        $this->buffer = fread($this->input, min(self::BUFFER_BYTES, $this->size - $this->position));
        if ($this->buffer === false || $this->buffer === '') {
            throw new FileReadException('Cannot read picture input');
        }
        $this->bufferOffset = 0;
    }
}
