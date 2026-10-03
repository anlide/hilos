<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Auth\AccessLog\AccessLogPolicy;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\NotificationPreference as ObjectNotificationPreference;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Database\View\Item\UserMerge;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalWire;

/** Explicit JSON projections: authentication secrets never pass through generic serialization. */
final class FrameworkDataExportSections
{
    /**
     * @param int $userId Person whose framework records are exported
     * @param DataExportWriter $writer Archive serialization boundary
     * @throws HilosException When a collection cannot be read or a section cannot be written
     */
    public static function write(int $userId, DataExportWriter $writer): void
    {
        $identities = Hilos::$db->identities->exportEntries($userId);
        $writer->section('account', [
            'id' => $userId,
            'since' => DataExportTime::iso($identities[0]->createdAt ?? null),
        ]);

        // Who did a rename is somebody else's data, so the author stays out of the copy.
        $renames = [];
        foreach (Hilos::$db->userRenames->byUser($userId) as $rename) {
            $renames[] = [
                'from' => $rename->oldName,
                'to' => $rename->newName,
                'at' => DataExportTime::iso($rename->renamedAt),
            ];
        }
        $writer->section('renames', $renames);

        // Both sides of a merge are the person's: their account folded away, and the accounts
        // folded into theirs. Null where it is not known - a row carried over without its
        // moment, an older row whose survivor was erased before HIL-1200.
        $merges = [];
        $foldedAway = Hilos::$db->userMerges[$userId];
        if ($foldedAway !== null) {
            $merges[] = self::merge($foldedAway);
        }
        foreach (Hilos::$db->userMerges->foldedInto($userId) as $foldedIn) {
            $merges[] = self::merge($foldedIn);
        }
        $writer->section('merges', $merges);

        $signInMethods = [];
        foreach ($identities as $identity) {
            $signInMethods[] = [
                'type' => $identity->type,
                'identifier' => $identity->identifier,
                'provider' => $identity->provider,
                'verified' => $identity->verified,
                'createdAt' => DataExportTime::iso($identity->createdAt),
            ];
        }
        $writer->section('sign_in_methods', $signInMethods);

        $passkeys = [];
        foreach (Hilos::$db->passkeyCredentials->listByUser($userId) as $passkey) {
            $passkeys[] = [
                'label' => $passkey->label,
                'createdAt' => DataExportTime::iso($passkey->createdAt),
                'lastUsedAt' => DataExportTime::iso($passkey->lastUsedAt),
            ];
        }
        $writer->section('passkeys', $passkeys);

        // The privacy text is the switch of both below (HIL-1174): an address the text does not
        // keep, or a log it does not keep, is not handed out even while a row still holds one.
        $keepsSessionAddress = AccessLogPolicy::keepsSessionAddress();
        $sessions = [];
        foreach (Hilos::$db->sessions->findByUserId($userId) as $session) {
            if ($session->impersonatorUserId !== null) {
                continue;
            }
            $sessions[] = [
                'startedAt' => DataExportTime::iso($session->createdAt),
                'lastSeenAt' => DataExportTime::iso($session->lastSeenAt),
                'device' => $session->deviceName,
                'address' => $keepsSessionAddress ? $session->ipAddress : null,
            ];
        }
        $writer->section('sessions', $sessions);

        $accessLog = [];
        if (AccessLogPolicy::keepsLog()) {
            foreach (Hilos::$db->accessLogEntries->ofUser($userId) as $entry) {
                $accessLog[] = [
                    'at' => DataExportTime::iso($entry->occurredAt),
                    'address' => $entry->ipAddress,
                    'event' => $entry->event,
                ];
            }
        }
        $writer->section('access_log', $accessLog);

        $since = null;
        $lastUsedAt = null;
        foreach (Hilos::$db->secondFactors->confirmedOf($userId) as $factor) {
            if ($since === null || $factor->confirmedAt < $since) {
                $since = $factor->confirmedAt;
            }
            if ($factor->lastUsedAt !== null && ($lastUsedAt === null || $factor->lastUsedAt > $lastUsedAt)) {
                $lastUsedAt = $factor->lastUsedAt;
            }
        }
        $backupCodesLeft = 0;
        foreach (Hilos::$db->secondFactorBackupCodes->listByUser($userId) as $backupCode) {
            if ($backupCode->usedAt === null) {
                $backupCodesLeft++;
            }
        }
        $writer->section('second_factor', [
            'connected' => $since !== null,
            'since' => DataExportTime::iso($since),
            'lastUsedAt' => DataExportTime::iso($lastUsedAt),
            'backupCodesLeft' => $backupCodesLeft,
        ]);

        if (Hilos::hasFeature(HilosFeature::NOTIFICATIONS)) {
            $notifications = [];
            foreach (Hilos::$db->notifications->whereColumnIs(ObjectNotification::userId, $userId) as $notification) {
                $notifications[] = [
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'createdAt' => DataExportTime::iso($notification->createdAt),
                    'readAt' => DataExportTime::iso($notification->readAt),
                ];
            }
            $writer->section('notifications', $notifications);

            $preferences = [];
            foreach (Hilos::$db->notificationPreferences->whereColumnIs(ObjectNotificationPreference::userId, $userId) as $preference) {
                $preferences[] = ['channel' => $preference->channel, 'enabled' => $preference->enabled];
            }
            $writer->section('notification_preferences', $preferences);
        }

        $subscriptions = [];
        // Push storage is optional; store-only projects have no subscription table.
        if (Schema::getTable(EntityPushSubscription::_table) !== null) {
            foreach (Hilos::$db->pushSubscriptions->whereColumnIs(ObjectPushSubscription::userId, $userId) as $subscription) {
                $subscriptions[] = [
                    'device' => $subscription->deviceName,
                    'createdAt' => DataExportTime::iso($subscription->createdAt),
                    'lastSeenAt' => DataExportTime::iso($subscription->lastSeenAt),
                    'goneAt' => DataExportTime::iso($subscription->goneAt),
                ];
            }
        }
        $writer->section('push_subscriptions', $subscriptions);

        // Keep every acceptance, including revisions the catalog no longer declares and the profile omits.
        $writer->section('legal_acceptances', self::legalAcceptances($userId));

        $deletion = Hilos::$db->accountDeletions->liveOf($userId);
        $writer->section('account_deletion', $deletion === null ? null : [
            'requestedAt' => DataExportTime::iso($deletion->requestedAt),
            'effectiveAt' => DataExportTime::iso($deletion->effectiveAt),
            'canceledAt' => DataExportTime::iso($deletion->canceledAt),
        ]);
    }

    /**
     * @param int $userId Person whose acceptance history is exported
     * @return list<array<string, mixed>> Accepted revisions in timestamp/id order
     * @throws HilosException When acceptance records or declared revision text cannot be read
     */
    private static function legalAcceptances(int $userId): array
    {
        $rows = [];
        foreach (Hilos::$db->legalAcceptances->ofUser($userId) as $acceptance) {
            $revision = null;
            $document = LegalDocument::tryFrom($acceptance->document);
            if ($document !== null) {
                foreach (LegalCatalogResolver::revisions($document) as $declared) {
                    if ($declared->id === $acceptance->revisionId) {
                        $revision = $declared;
                        break;
                    }
                }
            }

            $rows[] = [
                'document' => $acceptance->document,
                'revisionId' => $acceptance->revisionId,
                'acceptedAt' => DataExportTime::iso($acceptance->acceptedAt),
                'inCode' => $revision !== null,
                'publishedOn' => $revision?->publishedOn,
                'effectiveOn' => $revision?->effectiveOn,
                'significance' => $revision?->significance->value,
                'clauses' => $revision === null ? null : LegalWire::clauses(LegalCatalogResolver::compose($document, $revision->id)),
            ];
        }

        return $rows;
    }

    /**
     * @param UserMerge $merge One merge the person is a side of
     * @return array{account: int, into: ?int, at: ?string} Which account was folded, into which, and when
     */
    private static function merge(UserMerge $merge): array
    {
        return [
            'account' => $merge->userId,
            'into' => $merge->survivorUserId,
            'at' => DataExportTime::iso($merge->mergedAt),
        ];
    }
}
