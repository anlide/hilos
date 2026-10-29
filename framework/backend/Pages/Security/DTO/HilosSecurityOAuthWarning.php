<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

/** A declared provider whose effective client pair is incomplete. */
final readonly class HilosSecurityOAuthWarning
{
    public const string PROVIDER_KEY = 'providerKey';
    public const string LABEL = 'label';
    public const string MISSING_FIELDS = 'missingFields';

    /**
     * @param string $providerKey Provider directory key
     * @param string $label Provider directory label
     * @param int $missingFields Number of absent client-pair fields
     */
    public function __construct(public string $providerKey, public string $label, public int $missingFields)
    {
    }

    /** @return array{providerKey: string, label: string, missingFields: int} Nonsecret provider warning */
    public function toArray(): array
    {
        return [self::PROVIDER_KEY => $this->providerKey, self::LABEL => $this->label, self::MISSING_FIELDS => $this->missingFields];
    }
}
