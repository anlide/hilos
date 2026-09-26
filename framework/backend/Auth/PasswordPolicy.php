<?php

declare(strict_types=1);

namespace Hilos\Auth;

use Hilos\Auth\Exception\PasswordTooCommonException;
use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Fs\FrameworkData;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/**
 * The password rule every surface of one installation enforces (HIL-622, HIL-654).
 *
 * One rule, and deliberately one: registration, a password reset and the profile's
 * add-password all judge the same secret for the same account, so a minimum that differed
 * between them would only ever mean a password accepted at one door and refused at the
 * next. It moved into the framework with the sign-in commands, which is where the first
 * two of those three now live.
 *
 * The minimum used to be the only thing shared, and the sentence refusing it was typed out
 * again at every door - four of them by the time HIL-654 came to add a second rule. The
 * gate is now the shared thing: {@see assertValid()} is what a password passes before it is
 * written, wherever it was typed. The common breached-password list (HIL-650) is another
 * branch of that gate, shared by registration, recovery, and both profile password flows.
 *
 * Source and update: https://github.com/danielmiessler/SecLists at commit
 * 1a7bb9127eca9e6ff2fc0301c597fe6e16a0cb56, file
 * Passwords/Common-Credentials/100k-most-used-passwords-NCSC.txt (UK NCSC 2019 / HIBP).
 * Rebuild in the framework PHP container: remove trailing CR/LF from each source line,
 * keep strlen() >= MIN_LENGTH, apply mb_strtolower(), remove duplicates, sort(SORT_STRING),
 * and write one password per LF-terminated line to framework/data/common-passwords.txt.
 * The pinned build has 46,528 entries. The framework owns updates: rebuild from the chosen
 * source revision and update the count in FrameworkDataTest explicitly. Projects configure
 * nothing. Source attribution and licenses are in the root THIRD_PARTY_NOTICES.md.
 */
final class PasswordPolicy
{
    /** @var int Minimum accepted password length, in bytes */
    public const int MIN_LENGTH = 8;

    private const string COMMON_PASSWORDS_FILE = 'common-passwords.txt';

    /** @var array<string, true>|null Lowercased passwords, loaded once per process */
    private static ?array $commonPasswords = null;

    /**
     * Refuses a password that may not be written, whichever door it arrived at.
     *
     * Length is judged first, then the common-password list, then equality with the current
     * password. Membership in the list is public information and says nothing about the
     * account, so it takes precedence even when the proposed password is also unchanged.
     *
     * The last rule takes a verdict rather than an identity, and the caller is the one
     * that produces it. `verifyPassword()` lives on both layers of the identity - the
     * object item and the view item over it - with no interface across the pair, and the
     * callers hold different ones: a recovery holds the object layer, the profile holds
     * the view. A bool keeps this a rule instead of making it the place where those two
     * layers have to be reconciled.
     *
     * A door where nothing can match passes `false` and gets the length and list checks -
     * registration, and adding a password to an account that has none (HIL-406).
     *
     * WHERE THE CALL GOES matters as much as what it answers, and it is not this class's
     * to enforce: the verdict must be computed only AFTER the caller has proved the right
     * to the operation. A form that judged the new password first would answer "that is
     * already your password" to anybody who guessed it, without ever having to type the
     * current one - which is the account's password handed to whoever asked.
     *
     * @param string $newPassword Plaintext password about to be written
     * @param bool $matchesCurrentPassword Whether that password is the one the account already has
     * @throws ValueTooShortException When the password is shorter than {@see MIN_LENGTH}
     * @throws PasswordTooCommonException When the password is in the common-password list, ignoring case
     * @throws PasswordUnchangedException When the password is the account's current one
     * @throws FsException When the framework password list is missing, unreadable, or still a Git LFS pointer
     */
    public static function assertValid(string $newPassword, bool $matchesCurrentPassword): void
    {
        if (strlen($newPassword) < self::MIN_LENGTH) {
            throw new ValueTooShortException('Password must be at least ' . self::MIN_LENGTH . ' characters');
        }

        if (isset(self::commonPasswords()[mb_strtolower($newPassword)])) {
            throw new PasswordTooCommonException('That password is too common and easy to guess, choose a different one');
        }

        if ($matchesCurrentPassword) {
            throw new PasswordUnchangedException('That is already your password, choose a different one');
        }
    }

    /**
     * Publishes the cache only after a complete read, so a failed read cannot weaken the rule.
     *
     * @return array<string, true> Lowercased common passwords indexed as a set
     * @throws FsException When the list cannot be read as materialized framework data
     */
    private static function commonPasswords(): array
    {
        if (self::$commonPasswords === null) {
            $passwords = [];
            foreach (FsPath::readLines(FrameworkData::path(self::COMMON_PASSWORDS_FILE)) as $line) {
                $passwords[rtrim($line, "\r\n")] = true;
            }
            self::$commonPasswords = $passwords;
        }

        return self::$commonPasswords;
    }
}
