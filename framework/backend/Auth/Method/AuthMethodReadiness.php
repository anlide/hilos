<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\Verification\CodeDeliveryAvailability;
use Hilos\Database\Object\Collection\OAuthProviders;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Utils\Logger;

/**
 * AuthMethodReadiness - whether this installation can serve a sign-in method at all (HIL-1080).
 *
 * The third fact about a method, beside WIRED (the project's directory, {@see AuthMethodDirectory})
 * and ENABLED (the administrator's switch, {@see EnabledAuthMethods}): READY. A provider is ready
 * when its client id and client secret both resolve non-empty - administrator over env over
 * recipe ({@see OAuthConfigResolver}); the mailed link when a letter leaves this installation
 * and the phone code when a code channel exists ({@see CodeDeliveryAvailability}); a password,
 * a passkey and any other method need nothing and are always ready.
 *
 * One place, three readers: the row of the sign-in methods screen
 * ({@see HilosSecuritySignInMethodsTable}), the method set every surface is handed (each entry
 * says whether it is ready, {@see EnabledAuthMethods::toWire()}), and the rule on the list of
 * switched-off methods ({@see AuthMethodsDisabledRule}), which refuses a list that leaves no
 * ready method on.
 *
 * ENABLED AND READY ARE TWO ANSWERS. Enabled is the administrator's decision, ready is whether
 * the installation can keep it; the set on the wire carries both, because the sign-in surfaces
 * offer only the ready methods while the switches of the methods screen show every enabled one,
 * an unready provider included.
 *
 * Every answer here fails OPEN, as {@see CodeDeliveryAvailability} does and for the same reason:
 * a wrong yes is the behavior before this class existed (an icon whose click is refused), while
 * a wrong no hides a way in and refuses the administrator's write of the method list.
 *
 * It costs no query. A provider's readiness reads its row, and the process holds the provider
 * rows in memory after the first lookup ({@see OAuthProviders}), so the handshake can ask on
 * every connection.
 */
final class AuthMethodReadiness
{
    /**
     * Whether the installation can serve a method.
     *
     * @param string $methodKey Method key (see AuthMethodKey)
     * @return bool True when the method can serve, or when its readiness could not be read
     */
    public static function isReady(string $methodKey): bool
    {
        try {
            if (str_starts_with($methodKey, AuthMethodKey::OAUTH_PREFIX)) {
                $descriptor = Hilos::oauthProviderDirectoryClass()::get($methodKey);

                return $descriptor !== null && new OAuthConfigResolver()->isReady($descriptor);
            }

            return match ($methodKey) {
                AuthMethodKey::MAGIC_LINK => new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_EMAIL),
                AuthMethodKey::SMS => new CodeDeliveryAvailability()->canDeliverTo(IdentifierDetection::KIND_PHONE),
                default => true,
            };
        } catch (HilosException $e) {
            Logger::warning('Sign-in method readiness could not be read; counting the method ready', [
                'methodKey' => $methodKey,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Narrows method keys to the ready ones, in the order given.
     *
     * @param list<string> $methodKeys Method keys (see AuthMethodKey)
     * @return list<string> The ready keys among them, in the same order
     */
    public static function readyKeys(array $methodKeys): array
    {
        return array_values(array_filter($methodKeys, self::isReady(...)));
    }
}
