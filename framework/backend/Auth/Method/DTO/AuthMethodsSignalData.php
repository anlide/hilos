<?php

declare(strict_types=1);

namespace Hilos\Auth\Method\DTO;

use Hilos\Auth\Method\EnabledAuthMethods;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;

/**
 * AuthMethodsSignalData - the installation's enabled sign-in methods, sent to every connection (HIL-427).
 *
 * Sent by {@see SettingsLibraryAgent} after a write that changed the set, so every open
 * sign-in surface rebuilds itself without asking. The entries are the same ones the handshake
 * carries ({@see HandshakeResponseSignalData}): the method key, and the name a provider's
 * button shows, null for a method that is not a provider. The whole set travels rather than
 * the change, so a surface never has to know what it held before.
 */
final class AuthMethodsSignalData extends BaseDTO implements SignalDataInterface
{
    public const string authMethods = 'authMethods';
    public const string key = 'key';
    public const string name = 'name';

    /**
     * @param list<array{key: string, name: ?string}> $authMethods Enabled methods in button order
     *     (see {@see EnabledAuthMethods::toWire()})
     */
    public function __construct(public readonly array $authMethods)
    {
    }

    /**
     * Reads one list of method entries, the node this frame and the handshake carry alike.
     *
     * @param array<array-key, mixed> $node Entries as they arrived
     * @return list<array{key: string, name: ?string}> Entries in the order they arrived
     * @throws InvalidFormatException When an entry is not a map or lacks its key
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
            ];
        }

        return $entries;
    }

    /**
     * @return array{authMethods: list<array{key: string, name: ?string}>} DTO payload for transport
     */
    public function toArray(): array
    {
        return [self::authMethods => $this->authMethods];
    }

    /**
     * Creates the DTO from its transport payload.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the list is missing or an entry lacks its key
     */
    public static function fromArray(array $data): static
    {
        return new static(self::entriesFrom(self::requireArray($data, self::authMethods)));
    }
}
