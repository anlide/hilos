<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingAccessorUnavailableException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosModerationChecks as StateHilosModerationChecks;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
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
     * Everything the row carries beyond the call is fixed here: a fresh request id, the opening
     * moment, the deadline the check timeout setting gives it now, and the verdict recipient the
     * kind names. The caller hands over what the kind looks at - the text for a text kind, the
     * complete upload for an image kind - and nothing else.
     *
     * No check of the whole collection precedes the creation: the creation door judges the new row
     * by the set it is born in, so an initiator brings into being the checks of its own set alone.
     *
     * @param string $acceptKey Connection awaiting the verdict
     * @param int $userId Person whose content is checked
     * @param string $kind Moderation kind key
     * @param ?string $text Text to check, for a kind that looks at text
     * @param ?string $clientUploadId Complete upload on the same connection, for a kind that looks at an image
     * @return HilosModerationCheck The opened check
     * @throws InvalidStateException When the project does not declare moderation
     * @throws InvalidArgumentException When the person is not a positive id, the kind is not one the project declares,
     *     the input does not match what the kind looks at, or the project's kind directory is malformed
     * @throws ValidationException When the kind looks at text with files, which a check cannot carry yet
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
    ): HilosModerationCheck {
        if (!Hilos::hasFeature(HilosFeature::MODERATION)) {
            throw new InvalidStateException('A moderation check is opened only in a project that declares HilosFeature::MODERATION');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException("A moderation check needs a positive person id, got {$userId}");
        }
        $declared = Hilos::moderationKinds()[$kind]
            ?? throw new InvalidArgumentException("Unknown moderation kind: {$kind}");
        self::assertInput($declared, $text, $clientUploadId);
        if ($this->collection->find($acceptKey, $kind) !== null) {
            throw new DuplicateValueException('This connection is already waiting on a check of this kind.');
        }

        $settings = Hilos::$setting
            ?? throw new SettingAccessorUnavailableException('Settings accessor is not initialized');
        $startedAt = TimeHelper::nowMs();
        $state = StateHilosModerationCheck::create(
            $acceptKey,
            $userId,
            $kind,
            RandomHelper::secureHex(self::REQUEST_ID_BYTES),
            $declared->verdictTo,
            $text,
            $clientUploadId,
            $startedAt,
            $startedAt + $settings[ModerationSettingsCatalog::CHECK_TIMEOUT_SEC]->int() * TimeConstants::MS_PER_SECOND,
        );
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Refuses an input that is not what the kind looks at.
     *
     * @param ModerationKind $kind Kind the check is opened for
     * @param ?string $text Text handed over
     * @param ?string $clientUploadId Upload handed over
     * @throws InvalidArgumentException When a text kind gets no text or an upload, or an image kind gets text or no upload
     * @throws ValidationException When the kind looks at text with files
     */
    private static function assertInput(ModerationKind $kind, ?string $text, ?string $clientUploadId): void
    {
        $matches = match ($kind->input) {
            ModerationInput::TEXT => $text !== null && $clientUploadId === null,
            ModerationInput::IMAGE => $text === null && $clientUploadId !== null && $clientUploadId !== '',
            ModerationInput::TEXT_WITH_FILES => throw new ValidationException(
                'A check of text with attached files is not supported yet.',
            ),
        };
        if (!$matches) {
            throw new InvalidArgumentException(
                "Moderation kind {$kind->key} looks at {$kind->input->value}, and the check was handed another input",
            );
        }
    }
}
