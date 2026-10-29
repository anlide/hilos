<?php

declare(strict_types=1);

namespace Hilos\Pages\Security\DTO;

/** An enabled non-OAuth sign-in method the installation cannot currently serve. */
final readonly class HilosSecurityMethodWarning
{
    public const string METHOD_KEY = 'methodKey';
    public const string LABEL = 'label';

    /**
     * @param string $methodKey Sign-in method directory key
     * @param string $label Sign-in method label
     */
    public function __construct(public string $methodKey, public string $label)
    {
    }

    /** @return array{methodKey: string, label: string} Unready method warning */
    public function toArray(): array
    {
        return [self::METHOD_KEY => $this->methodKey, self::LABEL => $this->label];
    }
}
