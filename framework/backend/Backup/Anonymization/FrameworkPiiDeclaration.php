<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Database\Entity\Item\AuthBlock;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\Notification;
use Hilos\Database\Entity\Item\NotificationDelivery;
use Hilos\Database\Entity\Item\NotificationPreference;
use Hilos\Database\Entity\Item\PasskeyCredential;
use Hilos\Database\Entity\Item\PushSubscription;
use Hilos\Database\Entity\Item\RegistrationReservation;
use Hilos\Database\Entity\Item\Session;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Entity\Item\UserVerification;

/**
 * FrameworkPiiDeclaration - the framework's own PII classification.
 *
 * The framework ships tables of its own (identities, sessions, verifications, push
 * subscriptions, notifications, settings), and a project cannot be asked to classify
 * them: it did not write them, and the coverage gate would then fail on every project
 * that adopts a new framework table. So the framework declares its own rows here, and
 * {@see PiiRegistry::fromCatalog()} merges a project's declaration over the top - a
 * project row for the same table replaces the framework row whole, which is how an
 * installation with a different judgement about a framework table says so.
 *
 * Three groups, each classified by a rule rather than table by table:
 *
 * - **Authentication secrets are purged.** A session, a verification code, a passkey
 *   credential, a held registration and a block are all token-shaped: their columns are
 *   NOT NULL and UNIQUE, so a hash leaves behind a structurally valid session and a
 *   usable credential. None of them carries an incoming foreign key, which is what makes
 *   {@see AnonymizationStrategy::PURGE} safe on them - the pass runs in archive order,
 *   not declaration order, so a purged parent with RESTRICT children fails after the
 *   import rather than at the gate.
 * - **Content written by people is rewritten in place.** Notification titles and bodies,
 *   a delivery's last error and a setting's value become the mask; an identity's login
 *   identifier becomes a derived address and its secret becomes NULL. The rows stay, so
 *   a staging copy still has the shape of the system it was taken from.
 * - **Analytics is classified per column, not per table.** Purging it whole is not
 *   available: its name and dictionary tables are RESTRICT parents of the fact tables,
 *   and an archive's alphabetical order puts them first. So the identifying columns are
 *   named one by one - tokens hashed, addresses nulled, free text masked - and the two
 *   tables whose payload is a NOT NULL `json` column are purged instead, which their
 *   all-SET NULL incoming keys allow.
 *
 * A row with an empty column map is a classification too, and the reason the list is so
 * long: it says "this table was looked at and holds no personal data", which is exactly
 * what the coverage gate demands of every table in an archive.
 */
final class FrameworkPiiDeclaration
{
    /**
     * Returns the framework's PII rows in the declaration form a catalog uses.
     *
     * Keys are Entity classes wherever the framework has one, so a table rename does not
     * silently un-classify a table; the analytics, change-log and migration tables have
     * no class at all and are named as themselves. A row for a table a project does not
     * create ({@see PushSubscription} on an installation without push) is skipped by the
     * gate rather than refused, so the framework may classify everything it ships.
     *
     * @return array<int, array<class-string|string, array<string, AnonymizationStrategy>|AnonymizationStrategy>>
     *     Table keys to their per-column strategies (or to {@see AnonymizationStrategy::PURGE}),
     *     keyed by connection index
     */
    public static function rows(): array
    {
        return [
            0 => [
                // The identifier is a login handle, so it has to stay unique and stay an
                // address; `provider` is the name of an OAuth vendor and names nobody.
                Identity::class => [
                    'identifier' => AnonymizationStrategy::FAKE_EMAIL,
                    'secret' => AnonymizationStrategy::NULLIFY,
                ],
                Session::class => AnonymizationStrategy::PURGE,
                UserVerification::class => AnonymizationStrategy::PURGE,
                PasskeyCredential::class => AnonymizationStrategy::PURGE,
                RegistrationReservation::class => AnonymizationStrategy::PURGE,
                AuthBlock::class => AnonymizationStrategy::PURGE,
                PushSubscription::class => AnonymizationStrategy::PURGE,
                Notification::class => [
                    'title' => AnonymizationStrategy::MASK,
                    'body' => AnonymizationStrategy::MASK,
                    'data' => AnonymizationStrategy::NULLIFY,
                ],
                NotificationDelivery::class => ['last_error' => AnonymizationStrategy::MASK],
                NotificationPreference::class => [],
                // A setting value is masked by default and a project that knows better
                // replaces the row; the alternative, staying silent, would make every
                // project classify a table the framework wrote.
                Setting::class => ['value' => AnonymizationStrategy::MASK],

                'hilos_change_log' => [
                    'old_value' => AnonymizationStrategy::MASK,
                    'new_value' => AnonymizationStrategy::MASK,
                ],
                'hilos_change_log_value' => [
                    'old_value' => AnonymizationStrategy::MASK,
                    'new_value' => AnonymizationStrategy::MASK,
                ],
                'hilos_change_log_table' => [],
                'hilos_change_log_field' => [],
                'migration' => [],

                // `sha1_hash` beside each of these two values is deliberately left alone:
                // it carries a UNIQUE deduplication index, and masking it would collapse
                // every row onto one value. A hash out of step with its text is the
                // cheaper of the two damages.
                'hilos_analytics_user_agent' => ['value' => AnonymizationStrategy::MASK],
                'hilos_analytics_accept_language' => ['value' => AnonymizationStrategy::MASK],
                'hilos_analytics_browser_session' => [
                    'session_token' => AnonymizationStrategy::HASH,
                    'user_identity_value' => AnonymizationStrategy::HASH,
                ],
                'hilos_analytics_ws_connection' => [
                    'accept_key' => AnonymizationStrategy::HASH,
                    'opened_ipv4' => AnonymizationStrategy::NULLIFY,
                    'opened_ipv6' => AnonymizationStrategy::NULLIFY,
                ],
                'hilos_analytics_ws_connection_ipv4_change' => [
                    'old_ipv4' => AnonymizationStrategy::NULLIFY,
                    'new_ipv4' => AnonymizationStrategy::NULLIFY,
                ],
                'hilos_analytics_ws_connection_ipv6_change' => [
                    'old_ipv6' => AnonymizationStrategy::NULLIFY,
                    'new_ipv6' => AnonymizationStrategy::NULLIFY,
                ],
                // Both hold a NOT NULL `json` column, which the mask is not valid JSON
                // for; every key pointing at them is ON DELETE SET NULL, so emptying them
                // costs the facts their payload and nothing else.
                'hilos_analytics_payload_json' => AnonymizationStrategy::PURGE,
                'hilos_analytics_page_params' => AnonymizationStrategy::PURGE,
                'hilos_analytics_action_name' => [],
                'hilos_analytics_signal_name' => [],
                'hilos_analytics_cron_name' => [],
                'hilos_analytics_page' => [],
                'hilos_analytics_page_session' => [],
                'hilos_analytics_user_action' => [],
                'hilos_analytics_agent_session' => [],
                'hilos_analytics_agent_user_action' => [],
                'hilos_analytics_agent_system_signal' => [],
                'hilos_analytics_agent_cron_signal' => [],
                'hilos_analytics_worker_session' => [],
                'hilos_analytics_worker_system_signal' => [],
                'hilos_analytics_api_request' => [],
                'hilos_analytics_api_agent_action' => [],
                'hilos_analytics_browser_session_user_agent_change' => [],
                'hilos_analytics_browser_session_accept_language_change' => [],
            ],
        ];
    }
}
