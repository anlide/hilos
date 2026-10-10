<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Theme\ThemeSettingsCatalog;

/**
 * The coordinator → the person's agent: write the theme choice this person submitted (HIL-1427).
 *
 * What {@see HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE} carries. The agent writes the
 * choice and returns this ask whole inside {@see UserThemePickWriteDoneSignalData}; the coordinator
 * then answers the browser and tells the person's sessions.
 */
final class UserThemePickWriteSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string themePick = 'themePick';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose choice is written and the index of their agent
     * @param string $themePick Light, dark or system
     * @param string $replySignal Agent signal the coordinator receives the answer under
     * @param string $acceptKey Accept key of the connection that submitted the choice
     * @param ?string $requestId Client-minted request id, or null when untracked
     * @param string $action Browser action name the acknowledgement is addressed to
     * @param ?string $successMessage Sentence to speak on success
     * @throws InvalidFormatException When the person id or theme choice is invalid
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $themePick,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if (!in_array($themePick, ThemeSettingsCatalog::THEME_VALUES, true)) {
            throw new InvalidFormatException(ThemeSettingsCatalog::THEME_VALUE_REFUSAL);
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Theme choice ask
     * @throws InvalidFormatException When the person, choice or waiting submit is malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            themePick: self::requireString($data, self::themePick),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, int|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::themePick => $this->themePick,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
