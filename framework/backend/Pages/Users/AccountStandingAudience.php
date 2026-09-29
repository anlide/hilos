<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalDocument;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountStandingStateSignalData;

/**
 * Keeps the open admin surfaces that show an account's standing in step with it (HIL-945).
 *
 * Two surfaces read the standing of other people: the card of one person, and the list of people
 * narrowed to those past a deadline. Neither hears of a change by itself - a block, a deletion
 * request and an acceptance are written by others, and a deadline passes on its own - so this holds
 * what each subscriber was last shown and compares it, on the serving agent's tick, with what the
 * resolver answers now ({@see AccountStandingResolver}). The resolver remembers, and drops what a
 * write changed, so an unchanged standing costs a comparison.
 *
 * A card whose person's standing moved is sent the new standing whole, through the card's own
 * road past the view mode's bridge ({@see AbstractHilosUserPage::standingFrame()}). A list window
 * narrowed to one document is sent again when the people past that document's deadline are no
 * longer the ones it was served with.
 *
 * Process-local, like the page subscriptions it follows: a subscriber is held in the process that
 * serves its page.
 */
final class AccountStandingAudience
{
    /** @var array<string, int> Person each card subscriber looks at, keyed by accept key */
    private static array $cards = [];

    /** @var array<string, AccountStanding> Standing each card subscriber was last shown, keyed by accept key */
    private static array $shown = [];

    /** @var array<string, true> Subscribers of the list of people, keyed by accept key */
    private static array $lists = [];

    /** @var array<string, array<string, string>> Document each list window was last narrowed to, by accept key and table */
    private static array $listDocuments = [];

    /** @var array<string, array<string, list<int>>> People each list window was last served with, by accept key and table */
    private static array $listPeople = [];

    /**
     * Holds a card subscriber and the standing its page answer showed.
     *
     * @param string $acceptKey Subscribing connection
     * @param int $userId Person the card shows
     * @throws HilosException When the person's standing cannot be read
     */
    public static function addSubscriber(string $acceptKey, int $userId): void
    {
        self::$cards[$acceptKey] = $userId;
        self::$shown[$acceptKey] = AccountStandingResolver::of($userId);
    }

    /**
     * Holds a subscriber of the list of people; what its windows are narrowed to is read on the tick.
     *
     * @param string $acceptKey Subscribing connection
     */
    public static function addListSubscriber(string $acceptKey): void
    {
        self::$lists[$acceptKey] = true;
    }

    /** @param string $acceptKey Connection leaving the surface or closing */
    public static function removeSubscriber(string $acceptKey): void
    {
        unset(
            self::$cards[$acceptKey],
            self::$shown[$acceptKey],
            self::$lists[$acceptKey],
            self::$listDocuments[$acceptKey],
            self::$listPeople[$acceptKey],
        );
    }

    /** Clears the process-local audience when the serving agent stops. */
    public static function reset(): void
    {
        self::$cards = [];
        self::$shown = [];
        self::$lists = [];
        self::$listDocuments = [];
        self::$listPeople = [];
    }

    /**
     * Sends what changed under the open cards and the narrowed lists.
     *
     * @param PageAgentInterface $agent Agent serving the surfaces; the card frames leave from it
     * @throws InvalidArgumentException When a frame cannot be named
     * @throws InvalidFormatException When a standing frame cannot be built for its person
     * @throws HilosException When a standing, a list of people or a window cannot be read
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        foreach (self::$cards as $acceptKey => $userId) {
            $standing = AccountStandingResolver::of($userId);
            if ($standing->toArray() === self::$shown[$acceptKey]->toArray()) {
                continue;
            }

            self::$shown[$acceptKey] = $standing;
            $pageClass = Hilos::appClass()::PAGES[HilosPageConstants::HILOS_USER] ?? null;
            if ($pageClass === null || !is_subclass_of($pageClass, AbstractHilosUserPage::class)) {
                continue;
            }
            $frame = $pageClass::standingFrame($acceptKey, new AccountStandingStateSignalData($userId, $standing->toArray()));
            if ($frame === null) {
                continue;
            }
            Hilos::$sr->queueSignal(
                signalSource: $agent->getAgentSignalSource(),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName(HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE),
                signalData: new WebSocketSignalData(data: $frame, targetAcceptKey: $acceptKey),
            );
        }

        foreach (array_keys(self::$lists) as $acceptKey) {
            foreach (Hilos::$sr?->getTableViewports($acceptKey) ?? [] as $tableKey => $viewport) {
                $value = $viewport->filter[AbstractHilosUsersTable::FILTER_LAPSED] ?? null;
                $document = is_string($value) ? LegalDocument::tryFrom($value) : null;
                if ($document === null) {
                    unset(self::$listDocuments[$acceptKey][$tableKey], self::$listPeople[$acceptKey][$tableKey]);
                    continue;
                }

                $people = AccountStandingResolver::lapsedUserIds($document);
                $served = (self::$listDocuments[$acceptKey][$tableKey] ?? null) === $document->value
                    ? self::$listPeople[$acceptKey][$tableKey]
                    : null;
                self::$listDocuments[$acceptKey][$tableKey] = $document->value;
                self::$listPeople[$acceptKey][$tableKey] = $people;
                // A window seen for the first time, or narrowed anew, was just served by the table itself.
                if ($served !== null && $served !== $people) {
                    Hilos::$browser?->sendTableWindow(HilosPageConstants::HILOS_USERS, $acceptKey, $viewport);
                }
            }
        }
    }
}
