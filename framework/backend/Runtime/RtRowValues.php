<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRelease;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use LogicException;
use Throwable;

/** Immutable segmented value, with an independently releasable root lease. */
final class RtRowValues
{
    private bool $closed = false;
    private ?RtRowRelease $retirement = null;

    /**
     * @internal Only the row storage creates values from completed pages.
     *
     * @param RtRowArena $arena Runtime storage owner
     * @param RtRowPage $root Completed immutable value root
     * @param RtRowRole $role Holder role
     */
    public function __construct(private readonly RtRowArena $arena, private readonly RtRowPage $root, private RtRowRole $role)
    {
        $arena->hold($root, $role);
    }

    /** Queues a constant-size root release; never destroys the value graph. */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Explicit synchronous boundary for application/native values.
     *
     * @param mixed $value Native value to segment
     * @param ?RtRowArena $arena Runtime owner, or the process compatibility owner
     * @param RtRowRole $role Holder role
     * @return self Immutable segmented value
     * @throws Throwable When the native value is not a supported runtime value
     */
    public static function fromNative(mixed $value, ?RtRowArena $arena = null, RtRowRole $role = RtRowRole::Resident): self
    {
        $owner = $arena ?? RtRowArena::process();
        // This door is explicitly synchronous and retires what its own encoding released; the
        // backlog of the arena stays for the RT pass. The resumable path uses encode/copy instead.
        $owner->openDoor();
        try {
            return RtWorkDriver::runSynchronously(RtRowWriter::encode($value, $owner, $role));
        } finally {
            $owner->closeDoor();
        }
    }

    /**
     * Carrier door for a field map handed over in either form: an array crosses the explicit
     * native boundary here, in the process compatibility owner; a segmented value is kept as it is.
     *
     * @param array<string, mixed>|self $values Native field map or segmented value
     * @return self Segmented value
     * @throws InvalidFormatException When a native value is not a supported runtime value
     * @throws LogicException When page preparation violates a lease lifetime
     */
    public static function carried(array|self $values): self
    {
        return $values instanceof self
            ? $values
            : self::completed(RtRowWriter::encode($values, RtRowArena::process(), RtRowRole::Transient));
    }

    /**
     * Explicit application materialization of a carried field map, either form.
     *
     * @param array<string, mixed>|self $values Native field map or segmented value
     * @return array<string, mixed> Native field map
     * @throws LogicException When the lease closed or the value is not a field map
     */
    public static function nativeMap(array|self $values): array
    {
        if (is_array($values)) {
            return $values;
        }
        $native = $values->toNative();

        return is_array($native) ? $native : throw new LogicException('A carried runtime value is a field map');
    }

    /**
     * Explicit synchronous read of one field of a row map: the walk is bounded by the row's field names.
     *
     * @param string $key Application field name
     * @return ?self Field lease, or null when the map does not carry the field
     * @throws InvalidFormatException When this value is not a field map or a page is malformed
     * @throws LogicException When this lease has already closed
     */
    public function field(string $key): ?self
    {
        return self::completed($this->reader()->field($key));
    }

    /**
     * @return bool Whether this field map carries no field at all
     * @throws LogicException When this lease has already closed
     */
    public function isEmptyMap(): bool
    {
        $this->ensureOpen();

        return $this->root->kind === RtRowKind::Map && $this->root->first === null;
    }

    /**
     * @param RtRowRole $role New holder role
     * @return self Independent O(1) lease on the same immutable root
     * @throws LogicException When this lease has already closed
     */
    public function retain(RtRowRole $role): self
    {
        $this->ensureOpen();

        return new self($this->arena, $this->root, $role);
    }

    /**
     * @internal Transfers this existing holder between memory roles in constant work.
     * Preparation must transfer an open lease. The immutable graph and every other
     * holder's accounting stay unchanged; installation performs no validation.
     *
     * @param RtRowRole $role New holder role
     */
    public function reclassify(RtRowRole $role): void
    {
        if ($this->role !== $role) {
            $this->arena->reclassify($this->root, $this->role, $role);
            $this->role = $role;
        }
    }

    /** Closes the lease immediately; its accounting retires only with the reference. */
    public function release(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->retirement = $this->arena->release($this->root, $this->role);
        }
    }

    /** @return bool Whether this lease still owns a reference or unfinished retirement work */
    public function isRetained(): bool
    {
        return !$this->closed || !$this->retirement->isFinished();
    }

    /**
     * @return RtRowReader A reader with its own transient lease
     * @throws LogicException When this lease has already closed
     */
    public function reader(): RtRowReader
    {
        $this->ensureOpen();

        return new RtRowReader($this->arena, $this->root);
    }

    /**
     * Explicit application materialization; replication uses reader() instead.
     *
     * @return mixed Native value
     * @throws LogicException When this lease has already closed
     */
    public function toNative(): mixed
    {
        $this->ensureOpen();

        return RtRowReader::materialize($this->root);
    }

    /**
     * @internal Attaches an immutable root without exposing mutable pages to a reader.
     *
     * @param RtRowArena $arena Destination storage owner
     * @param RtRowPage $entry Unpublished entry page
     * @param bool $key Whether this is the entry's key
     * @throws LogicException When closed or when the arenas differ
     */
    public function attach(RtRowArena $arena, RtRowPage $entry, bool $key): void
    {
        $this->assertArena($arena);
        ++$this->root->references;
        if ($key) {
            $entry->first = $this->root;
        } else {
            $entry->second = $this->root;
        }
        $entry->logicalBytes += $this->root->logicalBytes;
    }

    /**
     * @internal Whether a writer of that arena may reference this value's root rather than copy it.
     *
     * @param RtRowArena $arena Writer's storage owner
     * @return bool Whether this value's pages belong to that arena
     */
    public function isHeldIn(RtRowArena $arena): bool
    {
        return $arena === $this->arena;
    }

    /**
     * @internal Checks compatibility before a writer allocates an entry page.
     *
     * @param RtRowArena $arena Destination owner
     * @throws LogicException When closed or when the arenas differ
     */
    public function assertArena(RtRowArena $arena): void
    {
        $this->ensureOpen();
        if ($arena !== $this->arena) {
            throw new LogicException('A row writer must copy values from another arena');
        }
    }

    /**
     * @internal Snapshot infrastructure reads completed pages without materialization.
     * The caller must retain this lease throughout its page traversal.
     *
     * @param RtRowArena $arena Expected page owner
     * @return RtRowPage Immutable root, never a transferable ownership reference
     * @throws LogicException When the lease closed or belongs to another arena
     */
    public function rootIn(RtRowArena $arena): RtRowPage
    {
        $this->assertArena($arena);

        return $this->root;
    }

    /**
     * @internal Runs a provably bounded segmented operation to its end in one synchronous step, for a
     * carrier or a door that may prepare and apply at once. Unlike {@see RtWorkDriver::runSynchronously()}
     * it promises nothing of its own: the exceptions are the operation's, documented by the caller.
     *
     * @param Generator $operation Segmented operation yielding work requests
     * @return mixed Completed value
     */
    public static function completed(Generator $operation): mixed
    {
        while ($operation->valid()) {
            $request = $operation->current();
            $operation->send($request instanceof RtWork ? $request->bytes : 0);
        }

        return $operation->getReturn();
    }

    /**
     * @throws LogicException When this lease has already closed
     */
    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The RT value lease is closed');
        }
    }
}
