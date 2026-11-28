<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
use Hilos\HilosException;

/**
 * Read-only runtime item for one connection row — the session stage (HIL-509).
 *
 * The view twin of {@see StateHilosSessionConnection}: the session reference on top of the
 * presence stage, read the same way the two below it are. The stages are mirrored
 * on both layers so that "which stage is this project on" has a single answer.
 *
 * @template TState of StateHilosSessionConnection
 * @extends HilosConnection<TState>
 *
 * @property-read ?string $sessionToken Session cookie token this connection belongs to
 * @property-read ?int $sessionId Database session row id, or null when none is attached
 * @property-read ?int $languageId Language the tab shows, or null without the I18N feature or before one is decided
 * @property-read ?int $localeId Locale the tab shows, or null when no stored locale fits or no language is decided
 * @property-read ?string $browserLanguages Stored tags the tab's browser asked for, by weight, or null when it named nothing usable
 * @property-read ?string $connectionCountry Country of the tab's connection, ISO 3166-1 alpha-2, or null when it is unknown
 * @property-read ?string $addressLanguage Code of the language whose declared host the tab's page is on, or null on the main address
 */
abstract class HilosSessionConnection extends HilosConnection
{
    /**
     * Adds the session-stage fields to the base ones of {@see HilosConnection::__get()}.
     *
     * @param string $name Property name (sessionToken, sessionId, languageId, localeId, browserLanguages, connectionCountry,
     *     addressLanguage, or a presence-stage one)
     * @return mixed Property value or item actions
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            StateHilosSessionConnection::sessionToken => $this->_state->sessionToken,
            StateHilosSessionConnection::sessionId => $this->_state->sessionId,
            StateHilosSessionConnection::languageId => $this->_state->languageId,
            StateHilosSessionConnection::localeId => $this->_state->localeId,
            StateHilosSessionConnection::browserLanguages => $this->_state->browserLanguages,
            StateHilosSessionConnection::connectionCountry => $this->_state->connectionCountry,
            StateHilosSessionConnection::addressLanguage => $this->_state->addressLanguage,
            default => parent::__get($name),
        };
    }
}
