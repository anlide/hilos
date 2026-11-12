<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\Identity;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * The participants of an installation's account erasure, each collection covered by exactly one of
 * them or exempt by a stated reason.
 *
 * The framework's own participants come from {@see framework()}; a project adds its own through
 * {@see with()}, and the project API for declaring them comes later (HIL-1948).
 * SCAFFOLD: no live caller until the erasure coordinator opens its operation (HIL-1952).
 */
final class AccountErasureParticipantCatalog
{
    public const string PERSONAL = 'personal';
    public const string USERS_LIBRARY = 'users_library';
    public const string SESSIONS_HOLDER = 'sessions_holder';
    public const string DATA_EXPORT = 'data_export';
    public const string LEGAL_EXPORT = 'legal_export';

    /** Mounted framework collections the erasure leaves alone, and why. */
    public const array EXEMPT = [
        HilosDbContext::accountDeletions => AccountErasureExemption::Trace,
        HilosDbContext::accountOperations => AccountErasureExemption::ServiceRecord,
        HilosDbContext::accountOperationAssignments => AccountErasureExemption::ServiceRecord,
        HilosDbContext::accountOperationResults => AccountErasureExemption::ServiceRecord,
        HilosDbContext::secondFactorTrustRestrictions => AccountErasureExemption::InstallationPolicy,
        HilosDbContext::files => AccountErasureExemption::ReferenceNullified,
        HilosDbContext::registrationReservations => AccountErasureExemption::OutsideAccount,
        HilosDbContext::authBlocks => AccountErasureExemption::OutsideAccount,
        HilosDbContext::fileVariants => AccountErasureExemption::NotPersonal,
        HilosDbContext::settings => AccountErasureExemption::NotPersonal,
        HilosDbContext::verifierCircle => AccountErasureExemption::NotPersonal,
        HilosDbContext::oauthProviders => AccountErasureExemption::NotPersonal,
        HilosDbContext::languages => AccountErasureExemption::NotPersonal,
        HilosDbContext::languageNames => AccountErasureExemption::NotPersonal,
        HilosDbContext::countries => AccountErasureExemption::NotPersonal,
        HilosDbContext::countryNames => AccountErasureExemption::NotPersonal,
        HilosDbContext::locales => AccountErasureExemption::NotPersonal,
        HilosDbContext::i18nReflows => AccountErasureExemption::NotPersonal,
        HilosDbContext::probeValues => AccountErasureExemption::NotPersonal,
        HilosDbContext::moderationRules => AccountErasureExemption::NotPersonal,
    ];

    /**
     * @param list<AccountErasureParticipant> $participants Participants in census order
     * @throws InvalidTopologyException When a key or a collection is declared twice, or a covered collection is exempt
     */
    private function __construct(private readonly array $participants)
    {
        $errors = [];
        $keys = [];
        $coveredBy = [];
        foreach ($participants as $participant) {
            $key = $participant->key();
            if (isset($keys[$key])) {
                $errors[] = "Account erasure participant [{$key}] is declared twice";
            }
            $keys[$key] = true;
            foreach ($participant->collections() as $collection) {
                if (isset(self::EXEMPT[$collection])) {
                    $errors[] = "Collection [{$collection}] is covered by participant [{$key}]"
                        . ' and exempt as ' . self::EXEMPT[$collection]->value;
                }
                if (isset($coveredBy[$collection])) {
                    $errors[] = "Collection [{$collection}] is covered by both [{$coveredBy[$collection]}] and [{$key}]";
                }
                $coveredBy[$collection] ??= $key;
            }
        }
        if ($errors !== []) {
            throw InvalidTopologyException::forErrors(Hilos::appClass(), $errors);
        }
    }

    /**
     * @return self The framework's own participants, in census order
     * @throws InvalidTopologyException When the framework's participants overlap each other or the exemptions
     */
    public static function framework(): self
    {
        return new self([
            new AccountErasureFixedOwner(
                self::PERSONAL,
                [
                    HilosDbContext::users,
                    HilosDbContext::userRenames,
                    HilosDbContext::userMerges,
                    HilosDbContext::userPhotos,
                    HilosDbContext::identities,
                    HilosDbContext::passkeyCredentials,
                    HilosDbContext::secondFactors,
                    HilosDbContext::secondFactorBackupCodes,
                    HilosDbContext::secondFactorResets,
                    HilosDbContext::secondFactorSettings,
                    HilosDbContext::secondFactorTrusts,
                    HilosDbContext::stepUps,
                    HilosDbContext::legalAcceptances,
                    HilosDbContext::notifications,
                    HilosDbContext::notificationPreferences,
                    HilosDbContext::pushSubscriptions,
                ],
                HilosAgentType::HILOS_USER,
                true,
                self::hasPersonRow(...),
            ),
            new AccountErasureFixedOwner(
                self::USERS_LIBRARY,
                [HilosDbContext::verifications],
                HilosAgentType::HILOS_USERS_LIBRARY,
                false,
                self::hasVerifications(...),
            ),
            // The census is taken by the session holder itself, so its part is always expected.
            new AccountErasureFixedOwner(
                self::SESSIONS_HOLDER,
                [HilosDbContext::sessions, HilosDbContext::accessLogEntries],
                HilosAgentType::HILOS_SESSIONS_LIBRARY,
                false,
                static fn (int $userId): bool => true,
            ),
            new AccountErasureDeliveryParticipant(),
            new AccountErasureFixedOwner(
                self::DATA_EXPORT,
                [HilosDbContext::dataExports],
                HilosAgentType::HILOS_DATA_EXPORT,
                false,
                self::hasDataExport(...),
            ),
            new AccountErasureFixedOwner(
                self::LEGAL_EXPORT,
                [HilosDbContext::legalAcceptanceExports],
                HilosAgentType::HILOS_LEGAL,
                false,
                self::hasLegalAcceptanceExport(...),
            ),
        ]);
    }

    /**
     * @param AccountErasureParticipant ...$participants Project participants, asked after these
     * @return self New catalog; this one is left unchanged
     * @throws InvalidTopologyException When a key or a collection is declared twice, or a covered collection is exempt
     */
    public function with(AccountErasureParticipant ...$participants): self
    {
        return new self([...$this->participants, ...array_values($participants)]);
    }

    /** @return list<AccountErasureParticipant> Participants in census order */
    public function participants(): array
    {
        return $this->participants;
    }

    /**
     * @param int $userId Account of the erasure circle
     * @return bool Whether the person's row is still saved
     * @throws LogicException When the user collection is not configured
     * @throws InvalidArgumentException When the loaded row does not match the collection
     * @throws DatabaseException When the row lookup fails
     */
    private static function hasPersonRow(int $userId): bool
    {
        return isset(Hilos::$db->users[$userId]);
    }

    /**
     * Asks by the person's id and by every address of theirs, as the erasure removes them.
     *
     * @param int $userId Account of the erasure circle
     * @return bool Whether a challenge of the person is still saved
     * @throws LogicException When the identity collection is not configured
     * @throws InvalidArgumentException When a query or a loaded row is invalid
     * @throws DatabaseException When a lookup fails
     */
    private static function hasVerifications(int $userId): bool
    {
        $addresses = array_values(array_unique(array_map(
            static fn (Identity $identity): string => $identity->identifier,
            Hilos::$db->identities->listByUser($userId),
        )));

        return Hilos::$db->verifications->anyForUserOrIdentifiers($userId, $addresses);
    }

    /**
     * @param int $userId Account of the erasure circle
     * @return bool Whether the person's data copy is still saved
     * @throws HilosException When the query or wrapper creation fails
     */
    private static function hasDataExport(int $userId): bool
    {
        return Hilos::$db->dataExports->ofUser($userId) !== null;
    }

    /**
     * @param int $userId Account of the erasure circle
     * @return bool Whether the person's acceptance export order is still saved
     * @throws HilosException When the query or wrapper creation fails
     */
    private static function hasLegalAcceptanceExport(int $userId): bool
    {
        return Hilos::$db->legalAcceptanceExports->ofUser($userId) !== null;
    }
}
