<?php

declare(strict_types=1);

namespace Hilos\Tables\Security;

use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the framework OAuth providers table (HIL-286).
 *
 * One row per provider the project's directory declares: its name, whether it can sign
 * anyone in, how many of its required fields are still empty, the set/not-set state of
 * its secret and the layer its client id comes from - and its recipe, which the provider
 * screen shows as reference and nobody edits. The secret's value is not here and has no
 * field to travel in. The identity rides {@see providerKey}, never a field named `id`.
 */
final class HilosSecurityOAuthProvidersTableRow extends AbstractTableRow
{
    public const string providerKey = 'providerKey';
    public const string label = 'label';
    public const string builtIn = 'builtIn';
    public const string configured = 'configured';
    public const string missingFields = 'missingFields';
    public const string secretSet = 'secretSet';
    public const string clientIdSource = 'clientIdSource';
    public const string authorizeUrl = 'authorizeUrl';
    public const string tokenUrl = 'tokenUrl';
    public const string userInfoUrl = 'userInfoUrl';
    public const string subjectKey = 'subjectKey';
    public const string emailKey = 'emailKey';
    public const string nameKey = 'nameKey';

    /**
     * @param string $providerKey Provider key (row key), e.g. 'oauth:github'
     * @param string $label Human provider name
     * @param bool $builtIn Whether the recipe is a preset Hilos ships rather than one the project built
     * @param bool $configured Whether the client id and the secret both resolve non-empty
     * @param int $missingFields How many of the required fields resolve empty
     * @param bool $secretSet Whether a client secret is in force on any layer
     * @param string $clientIdSource Layer the client id comes from (db|env|default)
     * @param string $authorizeUrl Recipe: authorization endpoint
     * @param string $tokenUrl Recipe: token endpoint
     * @param string $userInfoUrl Recipe: userinfo endpoint
     * @param string $subjectKey Recipe: userinfo field holding the subject id
     * @param string $emailKey Recipe: userinfo field holding the email
     * @param string $nameKey Recipe: userinfo field holding the display name
     */
    public function __construct(
        public string $providerKey,
        public string $label,
        public bool $builtIn,
        public bool $configured,
        public int $missingFields,
        public bool $secretSet,
        public string $clientIdSource,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $userInfoUrl,
        public string $subjectKey,
        public string $emailKey,
        public string $nameKey,
    ) {
    }

    /**
     * Returns the stable table row key (the provider key).
     *
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->providerKey;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::providerKey;
    }

    /**
     * Serializes the row to the providers table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::providerKey => $this->providerKey,
            self::label => $this->label,
            self::builtIn => $this->builtIn,
            self::configured => $this->configured,
            self::missingFields => $this->missingFields,
            self::secretSet => $this->secretSet,
            self::clientIdSource => $this->clientIdSource,
            self::authorizeUrl => $this->authorizeUrl,
            self::tokenUrl => $this->tokenUrl,
            self::userInfoUrl => $this->userInfoUrl,
            self::subjectKey => $this->subjectKey,
            self::emailKey => $this->emailKey,
            self::nameKey => $this->nameKey,
        ];
    }

    /**
     * Builds a providers row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed providers table row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            providerKey: (string) $data[self::providerKey],
            label: (string) $data[self::label],
            builtIn: (bool) $data[self::builtIn],
            configured: (bool) $data[self::configured],
            missingFields: (int) $data[self::missingFields],
            secretSet: (bool) $data[self::secretSet],
            clientIdSource: (string) $data[self::clientIdSource],
            authorizeUrl: (string) $data[self::authorizeUrl],
            tokenUrl: (string) $data[self::tokenUrl],
            userInfoUrl: (string) $data[self::userInfoUrl],
            subjectKey: (string) $data[self::subjectKey],
            emailKey: (string) $data[self::emailKey],
            nameKey: (string) $data[self::nameKey],
        );
    }
}
