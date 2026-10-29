<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

use Hilos\Auth\OAuth\OAuthConfigField;
use Hilos\Auth\OAuth\OAuthConfigResolver;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Environment\Exception\EnvException;

/** Complete first-answer provider head without credential values. */
final readonly class HilosOAuthProviderHeadData
{
    public const string DATA = 'oauthProviderHead';

    /**
     * @param string $providerKey Provider directory key
     * @param string $label Provider directory label
     * @param bool $configured Whether the effective client pair is complete
     * @param int $missingFields Number of absent client-pair fields
     */
    public function __construct(
        public string $providerKey,
        public string $label,
        public bool $configured,
        public int $missingFields,
    ) {
    }

    /**
     * @param OAuthProviderDescriptor $descriptor Provider to project
     * @return self Provider identity and effective client-pair readiness
     * @throws DatabaseException When the provider's row cannot be read
     * @throws EnvException When the provider's environment configuration is invalid
     * @throws LogicException When collection classes are misconfigured or the descriptor has no recipe
     * @throws InvalidArgumentException When the provider row lookup is invalid
     */
    public static function fromDescriptor(OAuthProviderDescriptor $descriptor): self
    {
        $resolver = new OAuthConfigResolver();
        $clientId = $resolver->resolve($descriptor, OAuthConfigField::CLIENT_ID);
        $secret = $resolver->resolve($descriptor, OAuthConfigField::CLIENT_SECRET);
        $missingFields = (int) !$clientId->isSet + (int) !$secret->isSet;

        return new self($descriptor->key, $descriptor->label, $missingFields === 0, $missingFields);
    }

    /** @return array{providerKey: string, label: string, configured: bool, missingFields: int} Nonsecret provider head */
    public function toArray(): array
    {
        return [
            'providerKey' => $this->providerKey,
            'label' => $this->label,
            'configured' => $this->configured,
            'missingFields' => $this->missingFields,
        ];
    }
}
