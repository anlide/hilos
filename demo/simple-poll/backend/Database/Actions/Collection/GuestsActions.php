<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\Actions\Collection;

use Demo\SimplePoll\Database\Object\Collection\Guests as ObjectGuests;
use Demo\SimplePoll\Database\View\Collection\Guests as DbCollectionGuests;
use Demo\SimplePoll\Database\View\Item\Guest;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * GuestsActions - write operations for Guests collection.
 *
 * Thin delegates over {@see ObjectGuests}: the writes themselves are one query
 * each, and what belongs here instead is the demo's decision about what a visitor
 * is called (HIL-611).
 *
 * @extends DbActions<Guest, ObjectGuests>
 * @property-read DbCollectionGuests $collection
 * @property-read ObjectGuests $objectCollection
 */
final class GuestsActions extends DbActions
{
    /**
     * The numeric tail a generated guest name ends in - four digits, so two visitors
     * arriving in the same second are still told apart on screen. It agrees with the
     * range {@see UsersActions} draws account names from, and stands here in its own
     * right: the two are decided separately, and a demo that renamed its guests would
     * not thereby rename its administrators.
     *
     * The digits come from the tolerant axis of {@see RandomHelper}, which is why this
     * file stands in the RANDOM-SOURCE list: the number is a label on a screen, not a
     * credential. A visitor is recognized by the session cookie and by nothing else, so
     * guessing which four digits somebody else was given gains nothing.
     *
     * @var int Lowest suffix a generated guest name can carry
     */
    private const int NAME_SUFFIX_MIN = 1000;

    /** @var int Highest suffix a generated guest name can carry */
    private const int NAME_SUFFIX_MAX = 9999;

    /**
     * Returns the guest of a session, minting a name for it on first sight.
     *
     * The name is generated here and passed down, not decided by the persistence
     * layer, because a name is only wanted when a row is actually new - and only
     * this side knows what this demo calls a visitor.
     *
     * @param string $sessionToken Session cookie token of the visiting browser
     * @return Guest Guest of this session
     * @throws HilosException On database error
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function ensureForSession(string $sessionToken): Guest
    {
        $this->ensureCanWrite();

        $guest = $this->objectCollection->ensureForSession(
            $sessionToken,
            'Guest' . RandomHelper::integer(self::NAME_SUFFIX_MIN, self::NAME_SUFFIX_MAX),
        );

        return $this->createDbItemFromObject($guest);
    }

    /**
     * Drops the guest row of a session, if it has one.
     *
     * Called on the handshake of a session that carries an account: the visitor it
     * used to be has no meaning any more, and nothing asks whether it ever was one.
     *
     * @param string $sessionToken Session cookie token whose guest row goes
     * @throws HilosException On database error
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForSession(string $sessionToken): void
    {
        $this->ensureCanWrite();

        $this->objectCollection->deleteBySessionToken($sessionToken);
    }
}
