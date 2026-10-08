<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\Command\StepUpCommands;
use Hilos\Auth\StepUp\DTO\StepUpConfirmedSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * The one place an identity confirmation is recorded, and the one place it is told (HIL-1330).
 *
 * A confirmation is the session's, so every tab of it learns of one the moment it is written: a tab
 * standing on the confirmation step of the same operation passes it without a press. Two writers
 * record one today - an ordinary "Confirm" ({@see StepUpCommands::confirm()}) and a refused sign-in
 * of a blocked person, which counts towards the data copy
 * ({@see AbstractSessionsLibraryAgent}) - and both come here, so the frame cannot be forgotten by
 * one of them, and moving the write to the person's own agent (HIL-1407) moves one call.
 *
 * The frame leaves from the WRITER and not from the sessions library: the library is the session's
 * authority and nothing else, and a writer that has just recorded the row knows everything the
 * frame says.
 */
final class StepUpConfirmations
{
    /**
     * Records one operation as confirmed in one browser for the verification lifetime, and tells
     * every tab of that browser.
     *
     * The person's expired confirmations are cleared first, as every write did before. The frame
     * goes out AFTER the row is written, or the list it carries would not hold it yet.
     *
     * @param AbstractAgent $writer Agent recording the confirmation, which sends the frame
     * @param string $sessionToken Session cookie token of the browser that confirmed
     * @param int $userId Person the confirmation is recorded on
     * @param string $operation Declared operation key
     * @throws DatabaseException When a confirmation row cannot be read, written or removed
     * @throws InvalidArgumentException When the entity query is invalid, or the frame cannot be named or queued
     * @throws CreateNotAllowedException When the writer may not add a confirmation row
     * @throws WriteNotAllowedException When the writer may not update or remove a confirmation row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If an inserted row has no primary key
     * @throws EnvException When the verification lifetime cannot be read
     */
    public static function record(AbstractAgent $writer, string $sessionToken, int $userId, string $operation): void
    {
        $sessionTokenHash = ProtectedModeRuntime::hashSessionToken($sessionToken);
        Hilos::$db->stepUps->actions->deleteExpiredForUser($userId);
        Hilos::$db->stepUps->actions->confirm(
            $sessionTokenHash,
            $userId,
            $operation,
            date('Y-m-d H:i:s', time() + Hilos::$env[EnvConstants::HILOS_VERIFICATION_TTL_SEC]->int()),
        );

        $writer->sendToSession(HilosSignalConstants::HILOS_STEP_UP_CONFIRMED, $sessionTokenHash, self::frameFor($sessionTokenHash));
    }

    /**
     * Builds the frame of what one browser has a live confirmation of now, which may be nothing.
     *
     * @param string $sessionTokenHash Hash of the session cookie token
     * @return StepUpConfirmedSignalData Frame carrying every live operation key of the browser
     * @throws DatabaseException When the confirmations cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public static function frameFor(string $sessionTokenHash): StepUpConfirmedSignalData
    {
        return new StepUpConfirmedSignalData(Hilos::$db->stepUps->liveOperations($sessionTokenHash));
    }
}
