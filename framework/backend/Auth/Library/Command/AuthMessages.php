<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\Command;

/**
 * The sentences a sign-in command puts in front of a person, in one place (HIL-622).
 *
 * They came off a project's page together with the commands that print them. They are
 * gathered rather than scattered one-per-handler because most of them are shared: the
 * send cap refuses a registration code, a reset code and a magic link with the same
 * sentence, and one refusal said three ways is three chances to drift apart.
 *
 * What is NOT here is as deliberate. Every sentence below is either a redirection the
 * surface acts on or a fact the person can do something about; nothing here names which
 * check failed on a path where that would answer "does this account exist" for free. The
 * generic ones say so in their own note, because a later reader's instinct is to make
 * them more helpful.
 */
final class AuthMessages
{
    /**
     * Login failure for an address nobody has an account at.
     *
     * One of the three sentences that replaced the single generic "invalid email or
     * password" (HIL-414). The generic one was there to hide which addresses have
     * accounts; the live lookup in front of this form answers exactly that question
     * now, so keeping the login vague no longer withheld anything - it only left
     * the person guessing which half of what they typed was wrong.
     */
    public const string UNKNOWN_EMAIL = 'No account found for this email';

    /**
     * Login failure for an address whose account was never given a password.
     *
     * An account built by a sign-in link, a provider or a phone has none, and
     * telling somebody their password is wrong when there is no password to be
     * wrong sends them to a recovery flow that cannot help them either.
     */
    public const string NO_PASSWORD = 'This account has no password';

    /**
     * Login failure for a password that did not match the account's.
     */
    public const string WRONG_PASSWORD = 'Incorrect password';

    /**
     * Password-reset refusal for an address with no password to reset.
     *
     * Covers both an unknown address and an account that has no password: the
     * distinction changes nothing the person can act on here, and what they can act
     * on - that this form is not their way in - is the same sentence either way.
     */
    public const string NO_PASSWORD_TO_RESET = 'No password to reset for this email';

    /**
     * Generic failure message for every verification-code path (unknown, expired,
     * wrong, exhausted) so a response never discloses which case occurred.
     */
    public const string INVALID_CODE = 'Invalid or expired code';

    /**
     * Message for a send refused by the window cap (HIL-421). It says nothing about
     * whose address it is - the same sentence answers a mailbox that has an account
     * and one that does not - and it deliberately quotes no number, because the
     * window it would name is the one thing worth knowing to pace a script by.
     */
    public const string SEND_CAP = 'Too many codes have been sent to this address. Please try again later.';

    /**
     * Message for a registration submit on an address that already has an account.
     * It rides an outcome that moves the surface to sign-in, so it reads as a
     * redirection rather than a rejection; there is no anti-enumeration concern here
     * (registration legitimately reveals a taken address - login is where it matters).
     */
    public const string IDENTIFIER_TAKEN = 'This email already has an account';

    /**
     * Message for a code submitted against a registration hold that has run out.
     * Deliberately distinct from {@see self::INVALID_CODE}: the code may well
     * have been right, and telling the person it was invalid would send them looking
     * for a mistake they did not make.
     */
    public const string RESERVATION_EXPIRED = 'That registration expired, please start again';

    /**
     * Message for a sign-in link that no longer opens anything - expired, already
     * clicked, or mangled in the mail client. Distinct from
     * {@see self::INVALID_CODE} because nothing was typed: it rides an outcome
     * whose code lets the return screen offer a fresh link instead of a form.
     */
    public const string MAGIC_LINK_INVALID = 'This sign-in link is invalid or expired';

    /**
     * Message for a recovery whose code is no longer live - never issued, expired, or
     * spent while the person was still typing. Distinct from
     * {@see self::INVALID_CODE} for the same reason the registration one is: it
     * rides an outcome that rolls the surface back to the address field, so it has to
     * explain a move rather than accuse a typo.
     */
    public const string RESET_CODE_EXPIRED = 'That reset code is no longer valid, please start again';

    /**
     * Message for the losing save of a two-device recovery. The code is single-use, so
     * the second device is not wrong about anything - the password it was about to set
     * is simply not the one the account has now, and signing in is what is left to do.
     */
    public const string PASSWORD_ALREADY_CHANGED = 'The password was already changed, please sign in';

    /**
     * Generic failure message for a malformed phone number. A format error is not
     * an enumeration concern (it reveals nothing about who has an account), so the
     * SMS-request path can surface it directly rather than answering generically.
     */
    public const string INVALID_PHONE = 'Enter a valid phone number';

    /**
     * Failure message for a code channel this project does not offer for a phone
     * (HIL-492). Reaching it means the payload named a channel the surface never
     * drew, so it is a malformed request rather than something a person did - and,
     * like the phone format above, it discloses nothing about any account.
     */
    public const string UNKNOWN_CHANNEL = 'That code channel is not available';

    /**
     * Generic failure message for an OAuth account link (HIL-282). A bad, expired,
     * foreign-owned, or already-linked token all answer the same way so the wire
     * discloses nothing about the matched account beyond the collision it implies.
     */
    public const string INVALID_LINK = 'This account link is invalid or has expired';

    /**
     * Generic failure message for the passkey login path (HIL-284). A bad
     * challenge token, an unknown credential, a failed assertion, or a
     * counter regression all answer the same way so the wire discloses nothing
     * about which account exists or which check failed.
     */
    public const string INVALID_PASSKEY = 'Passkey sign-in could not be completed';

    /**
     * A registration payload whose base64url parts do not decode: the browser sent
     * something the ceremony cannot even start on, which is not a ceremony failure and
     * must not read as one.
     */
    public const string MALFORMED_PASSKEY_REGISTRATION = 'Malformed passkey registration payload';

    /**
     * Prefix of a refused enrollment, the one place a WebAuthn reason is passed through:
     * the person is signed in already, so nothing is disclosed by saying what the
     * authenticator objected to, and without it an enrollment that cannot work is
     * undebuggable.
     */
    public const string PASSKEY_REGISTRATION_FAILED = 'Passkey registration failed';

    /** The same authenticator, enrolled twice on one account. */
    public const string PASSKEY_ALREADY_REGISTERED = 'This passkey is already registered';

    /**
     * Refusal for an unknown OAuth provider named by a start payload (HIL-281).
     */
    public const string UNKNOWN_PROVIDER = 'Unknown authentication provider';

    /**
     * Generic failure for an OAuth callback whose provider or state did not verify
     * (HIL-281). One sentence for both, because the difference between them is only
     * ever interesting to somebody probing the callback.
     */
    public const string OAUTH_VERIFICATION_FAILED = 'OAuth verification failed';
}
