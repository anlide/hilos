<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Cluster\Exception\PeerTransportException;

/** One ordered ID stream on one live link; stores no per-retired-ID tombstones. */
final class UploadMemoryPeerSequence
{
    private ?string $prefix = null;

    private int $position = 0;

    /**
     * @param string $separator Existing minted ID suffix delimiter
     * @param bool $contiguous Whether this link observes every position in the stream
     */
    public function __construct(private readonly string $separator, private readonly bool $contiguous)
    {
    }

    /**
     * @param string $id Existing minted ID, including its boot/channel prefix
     * @return bool Whether the ordered stream already passed this identity
     * @throws PeerTransportException When the identity is malformed or belongs to another stream
     */
    public function hasPassed(string $id): bool
    {
        return $this->sequence($id) <= $this->position;
    }

    /**
     * @param string $id Next observed identity; pool streams may skip unrelated allocations
     * @throws PeerTransportException When identity order, prefix or syntax contradicts this stream
     */
    public function advance(string $id): void
    {
        $sequence = $this->sequence($id);
        if ($sequence <= $this->position || ($this->contiguous && $sequence - 1 !== $this->position)) {
            throw new PeerTransportException('Peer upload identity arrived outside its ordered stream');
        }
        $this->prefix ??= substr($id, 0, strrpos($id, $this->separator) + strlen($this->separator));
        $this->position = $sequence;
    }

    /**
     * @param string $id Minted identity to validate without changing the frontier
     * @return int Positive canonical sequence within this stream
     * @throws PeerTransportException When the identity is malformed or belongs to another stream
     */
    private function sequence(string $id): int
    {
        $separator = strrpos($id, $this->separator);
        if ($separator === false || $separator === 0) {
            throw new PeerTransportException('Peer upload identity has no minted sequence');
        }
        $prefix = substr($id, 0, $separator + strlen($this->separator));
        $suffix = substr($id, strlen($prefix));
        $sequence = (int)$suffix;
        if ($sequence <= 0 || (string)$sequence !== $suffix || ($this->prefix !== null && $this->prefix !== $prefix)) {
            throw new PeerTransportException('Peer upload identity has a foreign prefix or invalid sequence');
        }

        return $sequence;
    }
}
