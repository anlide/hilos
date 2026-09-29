<?php

declare(strict_types=1);

namespace Hilos\DataExport;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Item\NotificationPreference as ObjectNotificationPreference;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Hilos;
use Hilos\HilosException;

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

        $sessions = [];
        foreach (Hilos::$db->sessions->findByUserId($userId) as $session) {
            if ($session->impersonatorUserId !== null) {
                continue;
            }
            $sessions[] = [
                'startedAt' => DataExportTime::iso($session->createdAt),
                'lastSeenAt' => DataExportTime::iso($session->lastSeenAt),
                'device' => $session->deviceName,
            ];
        }
        $writer->section('sessions', $sessions);

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

        $deletion = Hilos::$db->accountDeletions->liveOf($userId);
        $writer->section('account_deletion', $deletion === null ? null : [
            'requestedAt' => DataExportTime::iso($deletion->requestedAt),
            'effectiveAt' => DataExportTime::iso($deletion->effectiveAt),
            'canceledAt' => DataExportTime::iso($deletion->canceledAt),
        ]);
    }
}
