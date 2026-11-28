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
 *
 * The stage also carries the language and the region the tab shows (HIL-109): languageId and
 * localeId, written through HilosSessionConnectionsActions::setTabLanguage() where the handshake
 * response is stamped. A new row starts with both null; they stay null without the I18N feature,
 * and localeId stays null when the region ladder ends below every stored locale.
 *
 * Beside them it keeps what the tab's browser asked for (HIL-2309): browserLanguages, the tags of its
 * Accept-Language in their stored form, written by the same call when the tab connects. A frame that
 * reaches the tab later carries no header for it, so its ladders are decided again from this list. It
 * stays null when the browser named nothing usable and without the I18N feature.
 *
 * It keeps the country of the tab's connection too (HIL-2311): connectionCountry, the ISO 3166-1 alpha-2
 * code the handshake frame brought, written by the same call when the tab connects and read by every
 * later frame's ladders. It stays null when the country is unknown and without the I18N feature.
 *
 * And it keeps the language of the address of the tab's page (HIL-2313): addressLanguage, the code of the
 * language whose declared host matched the host of the page's Origin, written by the same call when the tab
 * connects and read by every later frame's ladders. It is the declared language, not the outcome: whether
 * that language is available is decided by the ladder on every frame. It stays null on the main address,
 * without an Origin and without the I18N feature.
 */
abstract class HilosSessionConnection extends HilosConnection
{
    public const string sessionToken = 'sessionToken';
    public const string sessionId = 'sessionId';
    public const string languageId = 'languageId';
    public const string localeId = 'localeId';
    public const string browserLanguages = 'browserLanguages';
    public const string connectionCountry = 'connectionCountry';
    public const string addressLanguage = 'addressLanguage';

    protected const array BASE_FIELD_TYPES = parent::BASE_FIELD_TYPES + self::SESSION_FIELD_TYPES;
    protected const array BASE_FIELD_DEFAULTS = parent::BASE_FIELD_DEFAULTS + self::SESSION_FIELD_DEFAULTS;

    private const array SESSION_FIELD_TYPES = [
        self::sessionToken => '?text',
        self::sessionId => '?int',
        self::languageId => '?int',
        self::localeId => '?int',
        self::browserLanguages => '?text',
        self::connectionCountry => '?text',
        self::addressLanguage => '?text',
    ];
    private const array SESSION_FIELD_DEFAULTS = [
        self::sessionToken => null,
        self::sessionId => null,
        self::languageId => null,
        self::localeId => null,
        self::browserLanguages => null,
        self::connectionCountry => null,
        self::addressLanguage => null,
    ];

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

    /** Primary id of the language the tab shows, or null without the I18N feature or before one is decided. */
    private(set) ?int $languageId {
        get => $this->rowValues()[self::languageId] ?? null;
        set {
            $this->setRowValue(self::languageId, $value);
        }
    }

    /** Primary id of the locale the tab shows, or null when no stored locale fits or no language is decided. */
    private(set) ?int $localeId {
        get => $this->rowValues()[self::localeId] ?? null;
        set {
            $this->setRowValue(self::localeId, $value);
        }
    }

    /** Stored tags the tab's browser asked for, by weight, or null when it named nothing usable or without the I18N feature. */
    private(set) ?string $browserLanguages {
        get => ($this->rowValues()[self::browserLanguages] ?? null)?->toNative();
        set {
            $this->setRowValue(self::browserLanguages, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Country of the tab's connection, ISO 3166-1 alpha-2 ('UA'), or null when it is unknown or without the I18N feature. */
    private(set) ?string $connectionCountry {
        get => ($this->rowValues()[self::connectionCountry] ?? null)?->toNative();
        set {
            $this->setRowValue(self::connectionCountry, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Code of the language whose declared host the tab's page is on ('de'), or null on the main address or without the I18N feature. */
    private(set) ?string $addressLanguage {
        get => ($this->rowValues()[self::addressLanguage] ?? null)?->toNative();
        set {
            $this->setRowValue(self::addressLanguage, $value === null ? null : RtRowValues::fromNative($value));
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
