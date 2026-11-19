<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalDocument;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Users\AccountErasureProgress;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Users\AccountMergeAvailabilityResolver;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountStandingStateSignalData;
use Hilos\Utils\Logger;
use Throwable;

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
 * A card keeps the person's standing, erasure and merge progress, and merge availability in step.
 * When any moves it is sent the whole picture, through the card's own road past the view mode's bridge
 * ({@see AbstractHilosUserPage::standingFrame()}). A list window
 * narrowed to one document is sent again when the people past that document's deadline are no
 * longer the ones it was served with.
 *
 * The list carries one row bar per existing account: begun erasure takes precedence over open merge
 * progress (HIL-1955, HIL-2004). Its bars are one picture for every open list: the picture last sent, which a window
 * opened between two ticks is served from ({@see AbstractHilosUsersTable::progressSnapshot()}), and
 * which the tick compares with the resolver's answer - a bar that appeared or moved is sent whole, a
 * bar that is gone is sent ended. The tick holds the new picture before it sends a bar, since a page
 * sent again in the middle of its round must carry the current picture. With no list open the
 * picture is forgotten, and the next window reads it anew.
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

    /** @var array<string, ?array<string, mixed>> Erasure block each card subscriber was last shown, keyed by accept key */
    private static array $shownErasure = [];

    /** @var array<string, ?array<string, mixed>> Full merge block last shown to each card connection */
    private static array $shownMerge = [];

    /** @var array<string, true> Cards whose merge block is in a failure episode */
    private static array $mergeFailures = [];

    /** @var array<string, ?array{reserved: bool, available: bool}> Occupancy last shown to each card connection */
    private static array $shownAvailability = [];

    /** @var array<string, true> Cards whose occupancy is in a failure episode */
    private static array $availabilityFailures = [];

    /** @var array<string, true> Subscribers of the list of people, keyed by accept key */
    private static array $lists = [];

    /** @var array<string, array<string, string>> Document each list window was last narrowed to, by accept key and table */
    private static array $listDocuments = [];

    /** @var array<string, array<string, list<int>>> People each list window was last served with, by accept key and table */
    private static array $listPeople = [];

    /** @var ?array<int, TableProgressDTO> Composed row bar of each account the lists were last sent, null when no list holds one */
    private static ?array $progressBars = null;

    /** @var ?array<int, AccountErasureProgress> Resolver picture the account filter last read */
    private static ?array $erasureSource = null;

    /** @var array<int, TableProgressDTO> Bars filtered against the accounts present in that picture */
    private static array $filteredErasureBars = [];

    /** @var array<string, true> Cards whose erasure block is in a failure episode */
    private static array $erasureFailures = [];

    private static bool $progressBarsFailed = false;

    /**
     * Holds a card subscriber and the standing, progress and availability its page answer showed.
     *
     * @param string $acceptKey Subscribing connection
     * @param int $userId Person the card shows
     * @throws HilosException When the person's standing or erasure progress cannot be read
     * @throws PageInternalErrorException When merge progress or availability cannot be read
     */
    public static function addSubscriber(string $acceptKey, int $userId): void
    {
        self::$cards[$acceptKey] = $userId;
        self::$shown[$acceptKey] = AccountStandingResolver::of($userId);
        self::$shownErasure[$acceptKey] = AccountErasureProgressResolver::of($userId)?->toArray();
        try {
            self::$shownMerge[$acceptKey] = AccountMergeProgressResolver::of($userId)?->toArray();
            self::$shownAvailability[$acceptKey] = AccountMergeAvailabilityResolver::of($userId)->toArray();
        } catch (Throwable $failure) {
            throw new PageInternalErrorException('Account merge progress is unavailable.', previous: $failure);
        }
        unset(self::$mergeFailures[$acceptKey], self::$availabilityFailures[$acceptKey]);
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
            self::$shownErasure[$acceptKey],
            self::$shownMerge[$acceptKey],
            self::$mergeFailures[$acceptKey],
            self::$shownAvailability[$acceptKey],
            self::$availabilityFailures[$acceptKey],
            self::$erasureFailures[$acceptKey],
            self::$lists[$acceptKey],
            self::$listDocuments[$acceptKey],
            self::$listPeople[$acceptKey],
        );
        if (self::$lists === []) {
            self::$progressBars = null;
            self::forgetErasureFilter();
            self::$progressBarsFailed = false;
        }
    }

    /** Clears the process-local audience when the serving agent stops. */
    public static function reset(): void
    {
        self::$cards = [];
        self::$shown = [];
        self::$shownErasure = [];
        self::$shownMerge = [];
        self::$mergeFailures = [];
        self::$shownAvailability = [];
        self::$availabilityFailures = [];
        self::$lists = [];
        self::$listDocuments = [];
        self::$listPeople = [];
        self::$progressBars = null;
        self::forgetErasureFilter();
        self::$erasureFailures = [];
        self::$progressBarsFailed = false;
    }

    /** Drops only the account-existence filter when accounts leave the data set. */
    public static function forgetErasureFilter(): void
    {
        self::$erasureSource = null;
        self::$filteredErasureBars = [];
    }

    /**
     * A snapshot counts as sent, including before the page's after-response listener hook.
     * The last listener leaving, reset and a tick without listeners discard the picture.
     *
     * @return list<TableProgressDTO> Composed bars, with erasure taking precedence over merge
     * @throws HilosException When a progress source or an account cannot be read
     */
    public static function progressBars(): array
    {
        self::$progressBars ??= self::currentProgressBars();

        return array_values(self::$progressBars);
    }

    /**
     * @return list<TableProgressDTO> Erasure portion of the lists' shared picture
     * @throws HilosException When a progress source or an account cannot be read
     */
    public static function erasureBars(): array
    {
        return array_values(array_filter(self::progressBars(),
            static fn (TableProgressDTO $bar): bool => $bar->progressKey === AbstractHilosUsersTable::PROGRESS_KEY_ERASURE));
    }

    /**
     * @return list<TableProgressDTO> Merge portion of the lists' shared picture
     * @throws HilosException When a progress source or an account cannot be read
     */
    public static function mergeBars(): array
    {
        return array_values(array_filter(self::progressBars(),
            static fn (TableProgressDTO $bar): bool => $bar->progressKey === AbstractHilosUsersTable::PROGRESS_KEY_MERGE));
    }

    /**
     * Sends what changed under the open cards and the narrowed lists.
     *
     * A card whose person is gone gets no frame: the live 404 of its row closes it, and a frame
     * could overtake that 404 and bring the card's buttons back (HIL-1957).
     *
     * @param PageAgentInterface $agent Agent serving the surfaces; the card frames leave from it
     * @throws InvalidArgumentException When a frame cannot be named
     * @throws InvalidFormatException When a standing frame cannot be built for its person
     * @throws HilosException When standing, a list of people or a window cannot be read
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        foreach (self::$cards as $acceptKey => $userId) {
            // The person left: the live 404 of their row closes the card, and a 'none' frame could
            // overtake it and bring the buttons back (HIL-1957).
            if (!isset(Hilos::$db->users[$userId])) {
                continue;
            }
            $standing = AccountStandingResolver::of($userId);
            $erasure = self::cardErasure($acceptKey, $userId);
            $merge = self::cardMerge($acceptKey, $userId);
            $availability = self::cardAvailability($acceptKey, $userId);
            if ($standing->toArray() === self::$shown[$acceptKey]->toArray()
                && $erasure === self::$shownErasure[$acceptKey] && $merge === self::$shownMerge[$acceptKey]
                && $availability === self::$shownAvailability[$acceptKey]) {
                continue;
            }

            self::$shown[$acceptKey] = $standing;
            self::$shownErasure[$acceptKey] = $erasure;
            self::$shownMerge[$acceptKey] = $merge;
            self::$shownAvailability[$acceptKey] = $availability;
            $pageClass = Hilos::appClass()::PAGES[HilosPageConstants::HILOS_USER] ?? null;
            if ($pageClass === null || !is_subclass_of($pageClass, AbstractHilosUserPage::class)) {
                continue;
            }
            $frame = $pageClass::standingFrame(
                $acceptKey, new AccountStandingStateSignalData($userId, $standing->toArray(), $erasure, $merge, $availability),
            );
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

        self::sendProgressBars();

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

    /**
     * Keeps a broken progress block apart from the standing and the other subscribers.
     *
     * @param string $acceptKey Card connection whose failure episode is remembered
     * @param int $userId Account the card shows
     * @return ?array<string, mixed> Full progress block, or null while its source refuses
     */
    private static function cardErasure(string $acceptKey, int $userId): ?array
    {
        try {
            $erasure = AccountErasureProgressResolver::of($userId)?->toArray();
        } catch (Throwable $failure) {
            if (!isset(self::$erasureFailures[$acceptKey])) {
                Logger::error("Account card erasure progress failed: user={$userId}, error={$failure->getMessage()}");
                self::$erasureFailures[$acceptKey] = true;
            }

            return null;
        }
        if (isset(self::$erasureFailures[$acceptKey])) {
            Logger::info("Account card erasure progress recovered: user={$userId}");
            unset(self::$erasureFailures[$acceptKey]);
        }

        return $erasure;
    }

    /**
     * Clears only an unreadable merge block, logging once per connection's failure episode.
     *
     * @param string $acceptKey Card connection whose failure episode is remembered
     * @param int $userId Account the card shows
     * @return ?array<string, mixed> Full merge block, or null while its source refuses
     */
    private static function cardMerge(string $acceptKey, int $userId): ?array
    {
        try {
            $merge = AccountMergeProgressResolver::of($userId)?->toArray();
        } catch (Throwable) {
            if (!isset(self::$mergeFailures[$acceptKey])) {
                Logger::error("Account card merge progress unavailable: user={$userId}");
                self::$mergeFailures[$acceptKey] = true;
            }

            return null;
        }
        unset(self::$mergeFailures[$acceptKey]);

        return $merge;
    }

    /**
     * @param string $acceptKey Card connection whose failure episode is remembered
     * @param int $userId Account the card shows
     * @return ?array{reserved: bool, available: bool} Occupancy, or null while its source refuses
     */
    private static function cardAvailability(string $acceptKey, int $userId): ?array
    {
        try {
            $availability = AccountMergeAvailabilityResolver::of($userId)->toArray();
        } catch (Throwable) {
            if (!isset(self::$availabilityFailures[$acceptKey])) {
                Logger::error("Account card merge availability unavailable: user={$userId}");
                self::$availabilityFailures[$acceptKey] = true;
            }

            return null;
        }
        unset(self::$availabilityFailures[$acceptKey]);

        return $availability;
    }

    /**
     * Sends every open list the composed bars that changed since its last picture.
     *
     * The new picture is held before the first bar goes: a bar to a tab owed its page sends the page
     * whole instead, and a page sent again in the middle of the round must carry the current picture.
     *
     * @throws InvalidArgumentException Never: a row bar always names its row
     */
    private static function sendProgressBars(): void
    {
        if (self::$lists === []) {
            self::$progressBars = null;
            self::forgetErasureFilter();
            self::$progressBarsFailed = false;

            return;
        }

        try {
            $current = self::currentProgressBars();
            if (self::$progressBarsFailed) {
                Logger::info('Account list row progress recovered.');
                self::$progressBarsFailed = false;
            }
        } catch (Throwable $failure) {
            $current = [];
            if (!self::$progressBarsFailed) {
                Logger::error('Account list row progress failed: ' . $failure->getMessage());
                self::$progressBarsFailed = true;
            }
        }
        $previous = self::$progressBars ?? [];
        $changed = [];
        foreach ($current as $userId => $bar) {
            if (!isset($previous[$userId]) || $previous[$userId]->toArray() !== $bar->toArray()) {
                $changed[] = $bar;
            }
        }
        foreach ($previous as $userId => $bar) {
            if (!isset($current[$userId]) || $current[$userId]->progressKey !== $bar->progressKey) {
                $changed[] = $bar->progressKey === AbstractHilosUsersTable::PROGRESS_KEY_ERASURE
                    ? AbstractHilosUsersTable::erasureBarEnded($userId)
                    : AbstractHilosUsersTable::mergeBarEnded($userId);
            }
        }
        self::$progressBars = $current;

        foreach (array_keys(self::$lists) as $acceptKey) {
            foreach (array_keys(Hilos::$sr?->getTableViewports($acceptKey) ?? []) as $tableKey) {
                if (!Hilos::$browser?->pageViewportTable(HilosPageConstants::HILOS_USERS, $tableKey) instanceof AbstractHilosUsersTable) {
                    continue;
                }
                foreach ($changed as $bar) {
                    Hilos::$browser->publishTableProgress(HilosPageConstants::HILOS_USERS, $acceptKey, $tableKey, $bar);
                }
            }
        }
    }

    /**
     * @return array<int, TableProgressDTO> One bar per existing account, erasure before merge
     * @throws HilosException When a progress source or an account cannot be read
     */
    private static function currentProgressBars(): array
    {
        $merges = [];
        $operations = [];
        try {
            foreach (AccountMergeProgressResolver::open() as $operationId => $progress) {
                foreach ([$progress->survivorUserId, $progress->loserUserId] as $userId) {
                    if ($operationId > ($operations[$userId] ?? 0) && isset(Hilos::$db->users[$userId])) {
                        $operations[$userId] = $operationId;
                        $merges[$userId] = AbstractHilosUsersTable::mergeBar($userId, $progress);
                    }
                }
            }
        } catch (Throwable $failure) {
            throw new PageInternalErrorException('Account list merge progress is unavailable.', previous: $failure);
        }

        return self::currentErasureBars() + $merges;
    }

    /**
     * @return array<int, TableProgressDTO> Erasure bar of every existing account a begun erasure covers, keyed by account
     * @throws InvalidArgumentException Never: a row bar always names its row
     * @throws HilosException When the erasure progress or an account cannot be read
     */
    private static function currentErasureBars(): array
    {
        $source = AccountErasureProgressResolver::begun();
        if ($source === self::$erasureSource) {
            return self::$filteredErasureBars;
        }
        $bars = [];
        foreach ($source as $userId => $progress) {
            if (isset(Hilos::$db->users[$userId])) {
                $bars[$userId] = AbstractHilosUsersTable::erasureBar($userId, $progress);
            }
        }
        self::$erasureSource = $source;
        self::$filteredErasureBars = $bars;

        return $bars;
    }
}
