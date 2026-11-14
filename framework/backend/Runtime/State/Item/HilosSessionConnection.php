<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;
use LogicException;
use Throwable;
use Hilos\HilosException;

/**
 * Session stage of a connection: sessionToken and database sessionId follow presence.
 *
 * Login rotation keeps the socket identity and repoints its token through
 * HilosSessionConnectionsActions::repointSessionToken(). A different session gets
 * a new connection row. Project hooks always run after both framework stages.
 */
abstract class HilosSessionConnection extends HilosConnection
{
    public const string sessionToken = 'sessionToken';
    public const string sessionId = 'sessionId';

    protected const array BASE_FIELD_TYPES = parent::BASE_FIELD_TYPES + self::SESSION_FIELD_TYPES;
    protected const array BASE_FIELD_DEFAULTS = parent::BASE_FIELD_DEFAULTS + self::SESSION_FIELD_DEFAULTS;

    private const array SESSION_FIELD_TYPES = [self::sessionToken => '?text', self::sessionId => '?int'];
    private const array SESSION_FIELD_DEFAULTS = [self::sessionToken => null, self::sessionId => null];

    /** Session token, or null when this connection belongs to none. */
    private(set) ?string $sessionToken {
        get => ($this->rowValues()[self::sessionToken] ?? null)?->toNative();
        set {
            $this->setRowValue(self::sessionToken, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Database session id, or null when this connection belongs to none. */
    private(set) ?int $sessionId {
        get => $this->rowValues()[self::sessionId] ?? null;
        set {
            $this->setRowValue(self::sessionId, $value);
        }
    }

    /**
     * @param string $acceptKey WebSocket accept key
     * @param ?int $userId Authenticated user id
     * @param ?string $sessionToken Browser session token
     * @param ?int $sessionId Database session id
     * @return static Fresh connection with the legacy baseline
     * @throws HilosException When project initialization fails
     */
    final public static function create(
        string $acceptKey,
        ?int $userId,
        ?string $sessionToken = null,
        ?int $sessionId = null,
    ): static
    {
        try {
            $instance = new static();
            $instance->initBase($acceptKey, $userId, $sessionToken, $sessionId);
            RtWorkDriver::runSynchronously($instance->initOwn());
            $instance->markRtSyncBaseline();

            return $instance;
        } catch (HilosException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FrameworkLogicException('Session connection compatibility operation failed', previous: $exception);
        }
    }

    /**
     * @param string $acceptKey WebSocket accept key
     * @param ?int $userId Authenticated user id
     * @param ?string $sessionToken Browser session token
     * @param ?int $sessionId Database session id
     */
    protected function initBase(
        string $acceptKey,
        ?int $userId,
        ?string $sessionToken = null,
        ?int $sessionId = null,
    ): void
    {
        parent::initBase($acceptKey, $userId);
        $this->sessionToken = $sessionToken;
        $this->sessionId = $sessionId;
    }

    /**
     * @param RtRowReader $row Segmented full row
     * @return Generator<int, RtWork, int, void> Presence then session hydration
     * @throws InvalidFormatException When a base field is missing or mistyped
     * @throws LogicException When segmented copying violates writer lifetime
     */
    protected function hydrateBase(RtRowReader $row): Generator
    {
        yield from parent::hydrateBase($row);
        yield from $this->readFields($row, self::SESSION_FIELD_TYPES);
    }

    /**
     * @param RtRowWriter $out Segmented destination
     * @return Generator<int, RtWork, int, void> Presence then session export
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When the destination has closed
     */
    protected function exportBase(RtRowWriter $out): Generator
    {
        yield from parent::exportBase($out);
        yield from $this->exportFields($out, self::SESSION_FIELD_DEFAULTS);
    }

    /**
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Presence then session patch
     * @throws InvalidFormatException When a changed base field is mistyped
     * @throws LogicException When segmented copying violates writer lifetime
     */
    protected function applyBaseDiff(RtRowReader $diff): Generator
    {
        yield from parent::applyBaseDiff($diff);
        yield from $this->readFields($diff, self::SESSION_FIELD_TYPES, true);
    }
}
