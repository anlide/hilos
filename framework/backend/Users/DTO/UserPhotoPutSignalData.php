<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the person's agent: set the profile photo from a published file (HIL-1409).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PHOTO_PUT} carries, once
 * {@see HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED} has named the file.
 * {@see AbstractUserAgent} writes the person's one photo row and sends this ask back inside
 * {@see UserPhotoPutDoneSignalData}, so {@see AbstractUsersLibraryAgent} releases the former file
 * from the frame alone. The browser action is answered when the upload is sent, so this ask carries
 * no request id and no sentence.
 */
final class UserPhotoPutSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string fileId = 'fileId';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Owner of the published file, and the index of the agent the ask is for
     * @param int $fileId Registry file the photo is set from
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Always null: the profile photo action is answered when the upload is sent
     * @param string $action Browser action name, {@see HilosSignalConstants::PROFILE_PHOTO_SET}
     * @param ?string $successMessage Sentence to speak on success, always null: the library answers once it has continued
     * @throws InvalidFormatException When the person id or the file id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $fileId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if ($fileId <= 0) {
            throw new InvalidFormatException('File id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Photo set ask
     * @throws InvalidFormatException When the person, the file or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            fileId: self::requireInt($data, self::fileId),
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
            self::fileId => $this->fileId,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
