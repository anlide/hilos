<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Hilos;

/**
 * The one place a project says whose its collections are, and which of them the account erasure
 * leaves alone.
 *
 * A project points {@see Hilos::ACCOUNT_DATA_OWNER_DIRECTORY} at a subclass and overrides
 * {@see owners()} and {@see exempt()}; the framework's own collections are not declared here.
 * {@see AccountErasureParticipantCatalog::installation()} takes the owners after the framework's
 * participants, in the order of this directory.
 *
 * The owners are a list, not a map, so a key typed twice reaches the check instead of silently
 * replacing the first entry. A key is lower snake case with no colon, because the colon separates
 * the parts of an assignment key. One agent type owns for one entry at most, and a framework
 * owner's type owns for none: the type alone names the owner's part of an operation. The whole
 * declaration is checked before either half is answered, and every mistake is named at once.
 */
abstract class AccountDataOwnerDirectory
{
    /** Grammar of an owner key: lower snake case, so no colon of an assignment key. */
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Agent types of the framework's own participants, which no project owner may take. */
    private const array FRAMEWORK_OWNER_TYPES = [
        HilosAgentType::HILOS_USER,
        HilosAgentType::HILOS_USERS_LIBRARY,
        HilosAgentType::HILOS_SESSIONS_LIBRARY,
        HilosAgentType::HILOS_DATA_EXPORT,
        HilosAgentType::HILOS_LEGAL,
    ];

    /**
     * @return list<AccountDataOwner> Declared owners in directory order
     * @throws InvalidTopologyException When the owners or the exemptions are malformed
     */
    final public static function declaredOwners(): array
    {
        $owners = static::owners();
        self::refuseMalformed($owners, static::exempt());

        return $owners;
    }

    /**
     * @return array<string, AccountErasureExemption> Project collections the erasure leaves alone, by collection key
     * @throws InvalidTopologyException When the owners or the exemptions are malformed
     */
    final public static function declaredExemptions(): array
    {
        $exempt = static::exempt();
        self::refuseMalformed(static::owners(), $exempt);

        return $exempt;
    }

    /**
     * A project overrides this to name the owners of its collections:
     *
     * ```php
     * protected static function owners(): array
     * {
     *     return [new AccountDataOwner('chat', ['chatMessages', 'chatEvents'], 'chat', false, self::hasChatRows(...))];
     * }
     * ```
     *
     * @return list<AccountDataOwner> Owners in directory order; none by default
     */
    protected static function owners(): array
    {
        return [];
    }

    /** @return array<string, AccountErasureExemption> Project collection keys the erasure leaves alone, and why; none by default */
    protected static function exempt(): array
    {
        return [];
    }

    /**
     * @param array<mixed> $owners Owners as declared
     * @param array<mixed> $exempt Exemptions as declared
     * @throws InvalidTopologyException When the declaration has a mistake, naming every one
     */
    private static function refuseMalformed(array $owners, array $exempt): void
    {
        $errors = [...self::ownerErrors($owners), ...self::exemptionErrors($exempt)];
        if ($errors !== []) {
            throw InvalidTopologyException::forErrors(Hilos::appClass(), $errors);
        }
    }

    /**
     * @param array<mixed> $owners Owners as declared
     * @return list<string> What is wrong with them
     */
    private static function ownerErrors(array $owners): array
    {
        $errors = [];
        if (!array_is_list($owners)) {
            $errors[] = static::class . ' must declare its account data owners as a list';
        }
        $keys = [];
        $keyByType = [];
        foreach ($owners as $position => $owner) {
            if (!$owner instanceof AccountDataOwner) {
                $errors[] = static::class . " declares an account data owner at position {$position} that is " . get_debug_type($owner);
                continue;
            }
            if (preg_match(self::KEY_PATTERN, $owner->key) !== 1) {
                $errors[] = "Account data owner key [{$owner->key}] does not match " . self::KEY_PATTERN;
            }
            if (isset($keys[$owner->key])) {
                $errors[] = "Account data owner [{$owner->key}] is declared twice";
            }
            $keys[$owner->key] = true;
            if (in_array($owner->ownerType, self::FRAMEWORK_OWNER_TYPES, true)) {
                $errors[] = "Account data owner [{$owner->key}] names the framework owner type [{$owner->ownerType}]";
            } elseif (isset($keyByType[$owner->ownerType])) {
                $errors[] = "Agent type [{$owner->ownerType}] owns for both [{$keyByType[$owner->ownerType]}] and [{$owner->key}]";
            }
            $keyByType[$owner->ownerType] ??= $owner->key;
            if ($owner->collections === []) {
                $errors[] = "Account data owner [{$owner->key}] covers no collection";
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $exempt Exemptions as declared
     * @return list<string> What is wrong with them
     */
    private static function exemptionErrors(array $exempt): array
    {
        $errors = [];
        foreach ($exempt as $collection => $reason) {
            if (!is_string($collection)) {
                $errors[] = static::class . " exempts a collection under the key {$collection}, not a collection key";
            }
            if (!$reason instanceof AccountErasureExemption) {
                $errors[] = static::class . " exempts [{$collection}] for " . get_debug_type($reason)
                    . ', not an ' . AccountErasureExemption::class;
            }
        }

        return $errors;
    }
}
