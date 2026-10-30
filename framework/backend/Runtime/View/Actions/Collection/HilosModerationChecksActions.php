<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Files\Upload\UploadSnapshotCollection;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosModerationChecks as StateHilosModerationChecks;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Hilos\Runtime\View\Actions\Item\HilosModerationCheckActions;
use Hilos\Runtime\View\Collection\HilosModerationChecks;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use LogicException;
use Random\RandomException;

/**
 * Write API for the moderation checks, as a set (HIL-1625).
 *
 * One method: an initiator opens a check. Its end - the verdict applied, the wait given up, the
 * attempt replaced - is a write over a row the initiator has already found, and lives on
 * {@see HilosModerationCheckActions}.
 *
 * @extends RtActions<HilosModerationCheck, HilosModerationChecks, StateHilosModerationChecks>
 * @property-read StateHilosModerationChecks $stateCollection
 */
final class HilosModerationChecksActions extends RtActions
{
    /** Random bytes behind one request id; the id is their hex. */
    private const int REQUEST_ID_BYTES = 16;

    /**
     * Opens a check of one kind on one connection.
     *
     * Connection-bound checks draw a request id and keep a supplied future deadline, or fix one
     * from the current setting when none is supplied.
     * A session-bound name check keeps the accepted attempt's id and moments, only while its
     * current, fresh checking row matches. Its origin connection need no longer be live.
     *
     * No check of the whole collection precedes the creation: the creation door judges the new row
     * by the set it is born in, so an initiator brings into being the checks of its own set alone.
     *
     * @param string $acceptKey Connection awaiting the verdict
     * @param int $userId Person whose content is checked
     * @param string $kind Moderation kind key
     * @param ?string $text Text to check, for a kind that looks at text
     * @param ?string $clientUploadId Complete upload on the same connection, for a kind that looks at an image
     * @param ?string $sessionTokenHash Accepted name-change session hash, or null for a connection-bound check
     * @param ?string $requestId Accepted name-change request id, required with a session hash
     * @param ?int $deadlineAt Fixed future deadline in epoch milliseconds, required with a session hash
     * @param list<string> $clientUploadIds Ordered distinct nonempty upload ids for a text-with-files check
     * @param bool $imageCopy Whether to check a CONTAIN JPEG copy, for an image kind only
     * @param ?UploadSnapshotCollection $uploadSnapshots Immutable admitted attachment identities, or null for none
     * @param ?string $uploadHoldId First stage request id, or null to use the generated request id for a first snapshot
     * @return HilosModerationCheck The opened check
     * @throws InvalidStateException When moderation is off or the accepted name-change row is absent, stale or mismatched
     * @throws InvalidArgumentException When the person is not a positive id, the kind is not one the project declares,
     *     the input or session correlation does not match, a connection deadline expired, or the kind directory is malformed
     * @throws InvalidFormatException When the immutable snapshot and hold token do not agree
     * @throws DuplicateValueException When the connection already waits on a check of this kind
     * @throws SettingAccessorUnavailableException When the settings accessor is not initialized
     * @throws SettingException When the check timeout setting cannot be read as an integer
     * @throws DatabaseException When the persisted check timeout setting cannot be loaded
     * @throws RandomException When the platform's secure random source refuses
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not hold the set the check is born in
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws RtActionsCallbackNotSetException When the item factory is unavailable
     * @throws RtActionsItemClassException When the item factory returns an invalid class
     * @throws LogicException When an internal invariant is violated
     */
    public function open(
        string $acceptKey,
        int $userId,
        string $kind,
        ?string $text = null,
        ?string $clientUploadId = null,
        ?string $sessionTokenHash = null,
        ?string $requestId = null,
        ?int $deadlineAt = null,
        array $clientUploadIds = [],
        bool $imageCopy = false,
        ?UploadSnapshotCollection $uploadSnapshots = null,
        ?string $uploadHoldId = null,
    ): HilosModerationCheck {
        if (!Hilos::hasFeature(HilosFeature::MODERATION)) {
            throw new InvalidStateException('A moderation check is opened only in a project that declares HilosFeature::MODERATION');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException("A moderation check needs a positive person id, got {$userId}");
        }
        $declared = Hilos::moderationKinds()[$kind]
            ?? throw new InvalidArgumentException("Unknown moderation kind: {$kind}");
        self::assertInput($declared, $text, $clientUploadId, $clientUploadIds, $imageCopy);
        $uploadSnapshots ??= new UploadSnapshotCollection();
        self::assertUploadSnapshots($declared, $clientUploadId, $clientUploadIds, $uploadSnapshots, $uploadHoldId);
        if ($this->collection->find($acceptKey, $kind) !== null) {
            throw new DuplicateValueException('This connection is already waiting on a check of this kind.');
        }

        $startedAt = TimeHelper::nowMs();
        if ($sessionTokenHash === null) {
            if ($requestId !== null) {
                throw new InvalidArgumentException('A correlated name check must name its accepted session attempt.');
            }
            if ($deadlineAt !== null && $deadlineAt <= $startedAt) {
                throw new InvalidArgumentException('A moderation check deadline must be in the future.');
            }
            $requestId = RandomHelper::secureHex(self::REQUEST_ID_BYTES);
            if ($deadlineAt === null) {
                $settings = Hilos::$setting
                    ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
                $deadlineAt = $startedAt + $settings[ModerationSettingsCatalog::CHECK_TIMEOUT_SEC]->int() * TimeConstants::MS_PER_SECOND;
            }
        } else {
            if (
                $sessionTokenHash === '' || $requestId === null || $requestId === '' || $deadlineAt === null
                || $kind !== ModerationKindKey::PROFILE_NAME || !Hilos::hasFeature(HilosFeature::AUTH)
            ) {
                throw new InvalidArgumentException('Only an accepted profile name attempt may open a session-bound check.');
            }
            if (
                Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash] === null
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->staleSince() !== null
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->userId !== $userId
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->requestId !== $requestId
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->name !== $text
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->originAcceptKey !== $acceptKey
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->phase !== HilosProfileNameChange::PHASE_CHECKING
                || Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->deadlineAt !== $deadlineAt
                || $deadlineAt <= $startedAt
            ) {
                throw new InvalidStateException('The accepted name-change attempt is unavailable or no longer matches this check.');
            }
            $startedAt = Hilos::$rt->hilosProfileNameChanges[$sessionTokenHash]->startedAt;
        }
        $state = StateHilosModerationCheck::create(
            $acceptKey,
            $userId,
            $kind,
            $requestId,
            $declared->verdictTo,
            $text,
            $clientUploadId,
            $startedAt,
            $deadlineAt,
            $sessionTokenHash,
            $clientUploadIds,
            $imageCopy,
            $uploadSnapshots,
            $uploadHoldId,
        );
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * @param ModerationKind $kind Kind the check is opened for
     * @param ?string $clientUploadId Singular image upload
     * @param list<string> $clientUploadIds Ordered text attachment ids
     * @param UploadSnapshotCollection $snapshots Full immutable attachment identities
     * @param ?string $uploadHoldId First stage token, or null for the first stage
     * @throws InvalidArgumentException When the snapshot does not match the input or a token has no snapshot
     */
    private static function assertUploadSnapshots(
        ModerationKind $kind,
        ?string $clientUploadId,
        array $clientUploadIds,
        UploadSnapshotCollection $snapshots,
        ?string $uploadHoldId,
    ): void {
        if ($uploadHoldId === '' || (count($snapshots) === 0 && $uploadHoldId !== null)) {
            throw new InvalidArgumentException('An upload hold token must accompany nonempty snapshots.');
        }
        if (count($snapshots) === 0) {
            return;
        }
        $snapshotIds = [];
        foreach ($snapshots as $snapshot) {
            $snapshotIds[] = $snapshot->clientUploadId;
        }
        $matches = match ($kind->input) {
            ModerationInput::TEXT => false,
            ModerationInput::TEXT_WITH_FILES => $snapshotIds === $clientUploadIds,
            ModerationInput::IMAGE => in_array($clientUploadId, $snapshotIds, true),
        };
        if (!$matches) {
            throw new InvalidArgumentException('The upload snapshots must match the moderation input and attachment order.');
        }
    }

    /**
     * Refuses an input that is not what the kind looks at.
     *
     * @param ModerationKind $kind Kind the check is opened for
     * @param ?string $text Text handed over
     * @param ?string $clientUploadId Upload handed over
     * @param list<string> $clientUploadIds Ordered upload ids handed over
     * @param bool $imageCopy Whether to check an image copy
     * @throws InvalidArgumentException When the input does not match the kind, or upload ids are not distinct nonempty strings in a list
     */
    private static function assertInput(
        ModerationKind $kind,
        ?string $text,
        ?string $clientUploadId,
        array $clientUploadIds,
        bool $imageCopy,
    ): void {
        if (!array_is_list($clientUploadIds)) {
            throw new InvalidArgumentException('Moderation upload ids must be a list.');
        }
        foreach ($clientUploadIds as $uploadId) {
            if (!is_string($uploadId) || $uploadId === '') {
                throw new InvalidArgumentException('Moderation upload ids must be nonempty strings.');
            }
        }
        if (count($clientUploadIds) !== count(array_unique($clientUploadIds))) {
            throw new InvalidArgumentException('Moderation upload ids must be distinct.');
        }
        $matches = match ($kind->input) {
            ModerationInput::TEXT => $text !== null && $clientUploadId === null && $clientUploadIds === [] && !$imageCopy,
            ModerationInput::TEXT_WITH_FILES => $text !== null && $clientUploadId === null && !$imageCopy,
            ModerationInput::IMAGE => $text === null && $clientUploadId !== null && $clientUploadId !== '' && $clientUploadIds === [],
        };
        if (!$matches) {
            throw new InvalidArgumentException(
                "Moderation kind {$kind->key} looks at {$kind->input->value}, and the check was handed another input",
            );
        }
    }
}
