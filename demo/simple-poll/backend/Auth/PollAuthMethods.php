<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Auth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetector;

/**
 * PollAuthMethods - the auth methods this demo has actually wired (HIL-634).
 *
 * The project half of detection: the framework decides what is TRUE about an identifier,
 * and this decides what this project is willing to offer for it. The two are separate
 * because a method key named to a surface that has no handler behind it is worse than one
 * that is missing - it renders a button whose submit the backend then refuses.
 *
 * The set is assembled, not listed: the password, link and SMS keys are the commands the
 * users library carries, and the OAuth keys come out of {@see PollOAuthConfig}'s own
 * registry, so adding a provider there adds it here with nothing to keep in step.
 * `passkey` is deliberately absent - the surface offers a device key on an empty field
 * only (HIL-418).
 *
 * The OAuth keys stay in this set even though detection never answers with one (HIL-419).
 * Dropping them is {@see IdentifierDetector}'s decision and belongs to the framework, which
 * knows the surface has stopped showing provider buttons by the time an identifier exists;
 * this list answers a different question - what this demo has wired.
 *
 * A settings-owned registry of enabled methods is HIL-427; until it lands the set is what
 * the code wires, which is the honest answer for a demo.
 */
final class PollAuthMethods
{
    /**
     * Builds the method keys detection may name, in the order a surface shows them.
     *
     * @return list<string> Enabled method keys (see AuthMethodKey)
     */
    public static function enabledKeys(): array
    {
        return [
            AuthMethodKey::PASSWORD,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            ...PollOAuthConfig::buildProviderRegistry()->keys(),
        ];
    }

    /**
     * Builds the detector over this project's enabled methods.
     *
     * @return IdentifierDetector Detector answering with keys this demo can serve
     */
    public static function detector(): IdentifierDetector
    {
        return new IdentifierDetector(self::enabledKeys());
    }
}
