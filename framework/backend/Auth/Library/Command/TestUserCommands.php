<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

use Hilos\Auth\PhoneNumber;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\WiringRefusal;
use Throwable;

/** Creates fixture accounts through the users library's ordinary write APIs. */
final class TestUserCommands extends AbstractLibraryCommands
{
    /**
     * The account, verified identities and current legal acceptances stand together.
     * The project's new-account hook runs once, after the commit.
     *
     * @param string $email Account email, normalized before creation
     * @param ?string $passwordHash Precomputed password hash, or null for magic-link sign-in
     * @param ?string $themePick Account theme choice, or null for an uninitialized choice
     * @param ?string $phone Optional canonical E.164 SMS identity
     * @return int Created person's id
     * @throws InvalidFormatException When the email, hash or phone is malformed
     * @throws ValidationException When the theme choice is unknown
     * @throws HilosException When account creation, the transaction or the project hook fails
     */
    public function create(string $email, ?string $passwordHash, ?string $themePick, ?string $phone): int
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidFormatException('Enter a valid email address');
        }
        if ($passwordHash !== null && password_get_info($passwordHash)['algo'] === null) {
            throw new InvalidFormatException('Provide a recognized password hash');
        }
        if ($themePick !== null && !in_array($themePick, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new ValidationException(ThemeSettingsCatalog::THEME_VALUE_REFUSAL);
        }
        if ($phone !== null && (PhoneNumber::normalize($phone) !== $phone || $phone[1] === '0')) {
            throw new InvalidFormatException('Provide a canonical E.164 phone number');
        }

        $revisions = [];
        foreach (LegalCatalogResolver::documents() as $document) {
            $revisions[$document->value] = LegalCatalogResolver::latestRevision($document)->id;
        }

        Database::transactionStart();
        try {
            $userId = $this->library->createUser($this->displayNameFromEmail($email), $themePick);
            if ($passwordHash === null) {
                Hilos::$db->identities->createMagicLinkIdentity($userId, $email);
            } else {
                Hilos::$db->identities->createPasswordIdentityWithHash($userId, $email, $passwordHash, verified: true);
            }
            if ($phone !== null) {
                Hilos::$db->identities->createSmsIdentity($userId, $phone);
            }
            $this->library->legalAcceptanceCommands()->record($userId, $revisions);
            Database::transactionCommit();
        } catch (WiringRefusal $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }

        $this->library->afterUserCreated($userId, $email);

        return $userId;
    }
}
