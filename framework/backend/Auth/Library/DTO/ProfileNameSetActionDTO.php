<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Submit the acting person's new profile name. */
final class ProfileNameSetActionDTO extends ActionPayloadDTO
{
    public const string name = 'name';

    public const array SECRET_FIELDS = [];

    /** @param string $name Requested profile name */
    public function __construct(public readonly string $name)
    {
    }

    /** @return string Owned action name */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_NAME_SET;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed action with surrounding whitespace removed
     * @throws InvalidFormatException When the name is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(trim(self::requireString($data, self::name)));
    }

    /** @return array{name: string} Transport payload */
    public function toArray(): array
    {
        return [self::name => $this->name];
    }
}
