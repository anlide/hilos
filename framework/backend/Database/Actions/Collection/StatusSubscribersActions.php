<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Auth\Session\BrowserTimeZone;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StatusSubscribers as ObjectStatusSubscribers;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\Database\View\Collection\StatusSubscribers as DbCollectionStatusSubscribers;
use Hilos\Database\View\Item\StatusSubscriber;
use Hilos\HilosException;
use Hilos\Status\StatusSubscriberConfirmation;
use Random\RandomException;

/**
 * Creation joins the caller's transaction; only the result's raw token reaches afterCommit mail.
 *
 * @extends DbActions<StatusSubscriber, ObjectStatusSubscribers>
 * @property-read DbCollectionStatusSubscribers $collection
 * @property-read ObjectStatusSubscribers $objectCollection
 */
class StatusSubscribersActions extends DbActions
{
    private const int MAX_EMAIL_LENGTH = 254;

    /**
     * @param string $email Address normalized with lowercase and trim
     * @param int $at First request time in UTC epoch milliseconds
     * @param ?string $browserTimeZone Validated browser IANA zone snapshot, or null
     * @return StatusSubscriberConfirmation New subscription id and its ephemeral raw confirmation value
     * @throws HilosException When the address, moment, transaction, owner grant or persistence is invalid
     * @throws RandomException When secure entropy is unavailable
     */
    public function create(string $email, int $at, ?string $browserTimeZone = null): StatusSubscriberConfirmation
    {
        $this->ensureCanCreate();
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('Subscription creation requires the caller transaction');
        }
        $email = mb_strtolower(trim($email));
        if (strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Enter a valid email address.');
        }
        if ($at < 0 || $at > PHP_INT_MAX - ObjectStatusSubscriber::CONFIRMATION_TTL_MS) {
            throw new ValidationException('The subscription time is outside the supported range.');
        }
        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $subscriber = $objectClass::create();
        $subscriber->email = $email;
        $subscriber->confirmed = false;
        $subscriber->subscribed_at = $at;
        $subscriber->browser_time_zone = BrowserTimeZone::normalize($browserTimeZone);
        $subscriber->sync();
        $subscriber->initializeUnsubscribeToken();
        $confirmation = $subscriber->issueConfirmation($at)
            ?? throw new DatabaseException('New subscription has no confirmation challenge');
        $this->addObjectToCollection($subscriber);
        return $confirmation;
    }
}
