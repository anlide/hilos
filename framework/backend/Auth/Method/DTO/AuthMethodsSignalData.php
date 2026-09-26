<?php

declare(strict_types=1);

namespace Hilos\Auth\Method\DTO;

use Hilos\Auth\Method\AuthMethodReadiness;
use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\Auth\Method\PasskeyAddressPolicy;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\AbstractHilosSecurityOAuthProviderPage;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;

/**
 * AuthMethodsSignalData - the installation's enabled sign-in methods, sent to every connection (HIL-427).
 *
 * Sent by {@see SettingsLibraryAgent} after a setting write that changed the frame, and by the
 * provider page ({@see AbstractHilosSecurityOAuthProviderPage}) after a provider field write
 * that changed it, so every open sign-in surface rebuilds itself without asking. The entries
 * are the same ones the handshake carries ({@see HandshakeResponseSignalData}): the method key,
 * the name a provider's button shows (null for a method that is not a provider), and whether
 * the installation can serve the method ({@see AuthMethodReadiness}, HIL-1080). The set is the
 * ENABLED methods, the unready ones included, because the switches of the sign-in methods
 * screen read this same set; a sign-in surface narrows it to the ready ones itself. The whole
 * set travels rather than the change, so a surface never has to know what it held before.
 *
 * The frame also carries whether a passkey may start an account on an unconfirmed address
 * ({@see PasskeyAddressPolicy}, HIL-1105). It rides here and not on a frame of its own because
 * nobody reads it apart from the set: the sign-in methods screen draws its switch beside the
 * passkey row, and the sign-in surface offers "create an account with a passkey" only when the
 * method is on AND the policy allows it. Two frames would arrive in either order, and a surface
 * would draw once from a set and a policy that never stood together.
 */
final class AuthMethodsSignalData extends BaseDTO implements SignalDataInterface
{
    public const string authMethods = 'authMethods';
    public const string key = 'key';
    public const string name = 'name';
    public const string ready = 'ready';
    public const string passkeyAllowsUnproven = 'passkeyAllowsUnproven';

    /**
     * @param list<array{key: string, name: ?string, ready: bool}> $authMethods Enabled methods in button order
     *     (see {@see EnabledAuthMethods::toWire()})
     * @param bool $passkeyAllowsUnproven Whether a passkey may start an account on an unconfirmed address
     *     (see {@see PasskeyAddressPolicy::allowsUnproven()})
     */
    public function __construct(public readonly array $authMethods, public readonly bool $passkeyAllowsUnproven)
    {
    }

    /**
     * Builds the frame as the installation stands now: the enabled set and the passkey policy, read together.
     *
     * The one assembly both senders and the handshake stamp share, so a frame can never carry a
     * set read under one policy and a policy read under another shape.
     *
     * @return self The frame to send
     * @throws DatabaseException When the sign-in method setting cannot be read
     * @throws SettingException When the sign-in method setting's catalog entry or stored value is invalid
     */
    public static function current(): self
    {
        return new self(EnabledAuthMethods::toWire(), PasskeyAddressPolicy::allowsUnproven());
    }

    /**
     * Reads one list of method entries, the node this frame and the handshake carry alike.
     *
     * @param array<array-key, mixed> $node Entries as they arrived
     * @return list<array{key: string, name: ?string, ready: bool}> Entries in the order they arrived
     * @throws InvalidFormatException When an entry is not a map, or lacks its key or its readiness
     */
    public static function entriesFrom(array $node): array
    {
        $entries = [];
        foreach ($node as $entry) {
            if (!is_array($entry)) {
                throw new InvalidFormatException('Each ' . self::authMethods . ' entry must be an object');
            }
            $entries[] = [
                self::key => self::requireString($entry, self::key),
                self::name => self::optionalString($entry, self::name),
                self::ready => self::requireBool($entry, self::ready),
            ];
        }

        return $entries;
    }

    /**
     * @return array{authMethods: list<array{key: string, name: ?string, ready: bool}>, passkeyAllowsUnproven: bool}
     *     DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            self::authMethods => $this->authMethods,
            self::passkeyAllowsUnproven => $this->passkeyAllowsUnproven,
        ];
    }

    /**
     * Creates the DTO from its transport payload.
     *
     * The passkey flag is required: every sender builds the frame through {@see current()}, so a
     * frame without it is a broken one. Reading an absent flag as no is the surface's answer,
     * not this parse boundary's.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the list or the flag is missing, or an entry lacks its key or its readiness
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::entriesFrom(self::requireArray($data, self::authMethods)),
            self::requireBool($data, self::passkeyAllowsUnproven),
        );
    }
}
