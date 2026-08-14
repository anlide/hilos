<?php

declare(strict_types=1);

namespace Demo\Chat\Auth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetector;

/**
 * ChatAuthMethods - the auth methods this demo has actually wired (HIL-414).
 *
 * The project half of detection: the framework decides what is TRUE about an
 * identifier, and this decides what this project is willing to offer for it. The
 * two are separate because a method key named to a surface that has no handler
 * behind it is worse than one that is missing - it renders a button whose submit
 * the backend then refuses.
 *
 * The set is assembled, not listed: the password, link and SMS keys are the auth
 * actions the main page carries, and the OAuth keys come out of
 * {@see ChatOAuthConfig}'s own registry, so adding a provider there adds it here
 * with nothing to keep in step. `passkey` is deliberately absent - the surface
 * offers a device key on an empty field only (HIL-418).
 *
 * A settings-owned registry of enabled methods is HIL-427; until it lands the set
 * is what the code wires, which is the honest answer for a demo.
 */
final class ChatAuthMethods
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
            ...ChatOAuthConfig::buildProviderRegistry()->keys(),
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
