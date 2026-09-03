<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosSessionToastStacks;
use Hilos\Runtime\View\Actions\Collection\HilosSessionToastStacksActions;

/**
 * HilosSessionToastStack - the toasts one browser session is still being shown (HIL-768).
 *
 * The row that makes the tabs of one session agree about a card the SERVER raised. A toast of
 * somebody's own action belongs to the tab it was pressed in and never reaches here; one
 * addressed to the session has to outlive the tab, because closing it in the second window and
 * meeting it again in the first is the disagreement this row exists to end.
 *
 * ONE ROW PER SESSION, and the id is the hash of its cookie token - the same form the freeze
 * stores and {@see AbstractAgent::resolveInitiatorSessionTokenHash()} hands out, so a sender
 * that knows who asked can name the row without ever holding the token itself. A row per
 * CONNECTION would have to copy the list into every tab and copy every answer back out again.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosSessionToastStacks}),
 * written by the agent that owns the session seam and by nobody else. It is runtime rather
 * than durable on purpose: a toast lives only while there is somebody looking at it, and a
 * restart that loses one loses a sentence nobody was reading.
 *
 * The two sets on it are answers from tabs, and they are asked in different shapes because
 * they mean different things: {@see self::TOAST_EXPIRED_BY} is per card - a countdown runs in
 * each tab separately - while {@see self::readingBy} is per row, since a cursor resting on the
 * stack holds the whole stack. Neither is ever pruned when a tab dies: the rule intersects
 * both with the live sockets when it runs ({@see HilosSessionToastStacksActions::settle()}),
 * so a closed tab neither vetoes forever nor expires on behalf of the living.
 */
final class HilosSessionToastStack extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosSessionToastStacks';

    public const string sessionTokenHash = 'sessionTokenHash';
    public const string toasts = 'toasts';
    public const string readingBy = 'readingBy';

    /** Server-minted name of one toast; what a tab answers about. */
    public const string TOAST_KEY = 'key';

    /** The sentence the person reads. */
    public const string TOAST_MESSAGE = 'message';

    /** One of {@see SessionToastSeverity}, as its value. */
    public const string TOAST_SEVERITY = 'severity';

    /** Who is speaking, drawn above the sentence. */
    public const string TOAST_SOURCE = 'source';

    /** Where clicking the card takes the person. */
    public const string TOAST_DESTINATION = 'destination';

    /** How many times this exact card has been raised; drawn as xN from two upwards. */
    public const string TOAST_REPEATS = 'repeats';

    /** Accept keys of the tabs whose countdown for this card has burned down. */
    public const string TOAST_EXPIRED_BY = 'expiredBy';

    /** Hash of the session cookie token these toasts are addressed to; also the row id. */
    private(set) string $sessionTokenHash = '';

    /**
     * @var list<array{key: string, message: string, severity: string, source: string,
     *     destination: string, repeats: int, expiredBy: list<string>}> Cards the session is still owed
     */
    private(set) array $toasts = [];

    /** @var list<string> Accept keys of the tabs where the stack is being read right now */
    private(set) array $readingBy = [];

    /**
     * Builds a stack row for one session.
     *
     * It takes the cards it is born with rather than starting empty, because a row with no
     * cards is a row that should not exist: the collection is emptied of it the moment its
     * last card goes, and creating one to fill it a line later would put that shape on the
     * wire in between.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @param list<array{key: string, message: string, severity: string, source: string,
     *     destination: string, repeats: int, expiredBy: list<string>}> $toasts Cards the row is born with
     * @return static Fresh stack row
     */
    public static function create(string $sessionTokenHash, array $toasts): static
    {
        $instance = new static();
        $instance->sessionTokenHash = $sessionTokenHash;
        $instance->toasts = $toasts;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Stack row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the stack is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->sessionTokenHash = self::requireString($row, self::sessionTokenHash);
        /** @var list<array{key: string, message: string, severity: string, source: string,
         *     destination: string, repeats: int, expiredBy: list<string>}> $toasts */
        $toasts = array_values(self::requireArray($row, self::toasts));
        $instance->toasts = $toasts;
        $instance->readingBy = self::requireStringList($row, self::readingBy);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * The id is not among the patchable fields: a stack is the session's, and a row that could
     * be re-addressed mid-flight would deliver one browser's sentence to another.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        /** @var list<array{key: string, message: string, severity: string, source: string,
         *     destination: string, repeats: int, expiredBy: list<string>}> $toasts */
        $toasts = array_values(self::patchArray($diff, self::toasts, $this->toasts));
        $this->toasts = $toasts;
        $this->readingBy = self::patchStringList($diff, self::readingBy, $this->readingBy);
    }

    /**
     * @return string Runtime collection key for session toast stacks
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the session token hash
     */
    public function getId(): string
    {
        return $this->sessionTokenHash;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::sessionTokenHash => $this->sessionTokenHash,
            self::toasts => $this->toasts,
            self::readingBy => $this->readingBy,
        ];
    }
}
