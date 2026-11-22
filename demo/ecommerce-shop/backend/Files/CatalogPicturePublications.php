<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Files;

use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CategoryPicturePublishReplyDTO;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Utils\Logger;

/**
 * The catalog picture publications one agent is waiting on: an upload handed to the files door and
 * the browser action that asked for it, until the door answers.
 *
 * The waiting requests live in this object, not in Hilos::$rt: the value is needed by the one process
 * of a monopolistic library - one per cluster - and by nobody else, and a restart loses only the request
 * in flight, which the browser's own action timer then answers (docs/agents/framework-first.md, "State").
 */
final class CatalogPicturePublications
{
    /** Refusal of an answer that does not carry exactly one file for the one upload asked. */
    private const string NOT_KEPT = 'Cannot keep the file';

    /** Separator of the connection and the upload in a key of a waiting request. */
    private const string KEY_SEPARATOR = "\0";

    /** @var array<string, ?string> Waiting requests: connection and upload id, to the browser's request id or null */
    private array $waiting = [];

    /**
     * @param string $replySignal Agent signal the files door answers under
     * @param string $action Browser action the answer acknowledges
     */
    public function __construct(
        private readonly string $replySignal,
        private readonly string $action,
    ) {
    }

    /**
     * Remembers the request and hands the upload to the files door.
     *
     * A refusal of the door forgets the request and goes on, so the page answers it the usual way.
     *
     * @param string $acceptKey Accept key of the connection the upload belongs to
     * @param string $clientUploadId Id the browser gave the upload
     * @param ?string $requestId Browser request id to acknowledge, or null when the action is not tracked
     * @throws LogicException When the files door is missing
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is one the wire does not allow
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     */
    public function ask(string $acceptKey, string $clientUploadId, ?string $requestId): void
    {
        $files = Hilos::$files ?? throw new LogicException('The files door is missing');
        $key = self::key($acceptKey, $clientUploadId);
        $this->waiting[$key] = $requestId;
        try {
            $files->publishUploads($acceptKey, CatalogPictureUploadTarget::NAME, [$clientUploadId], $this->replySignal);
        } catch (FeatureNotDeclaredException | InvalidFormatException | InvalidArgumentException $e) {
            unset($this->waiting[$key]);
            throw $e;
        }
    }

    /**
     * Acknowledges the browser action a publication answer belongs to.
     *
     * An answer nobody waits on is logged and dropped: its file stays unbound and goes to the janitor.
     *
     * @param AbstractAgent $agent Agent the request was asked through, which sends the acknowledgement
     * @param FilesPublishedSignalData $answer Answer of the files door
     * @throws InvalidArgumentException When the acknowledgement cannot be named, or the preview variant is not declared
     */
    public function answer(AbstractAgent $agent, FilesPublishedSignalData $answer): void
    {
        $key = self::key($answer->acceptKey, $answer->clientUploadIds[0]);
        if (!array_key_exists($key, $this->waiting)) {
            Logger::logAgentWarning(
                $agent->getId(),
                "Catalog picture publication answered for upload {$answer->clientUploadIds[0]} nobody waits on",
            );

            return;
        }

        $requestId = $this->waiting[$key];
        unset($this->waiting[$key]);
        if ($requestId === null) {
            return;
        }

        if ($answer->error !== null) {
            $agent->sendActionFail($answer->acceptKey, $this->action, $requestId, $answer->error);

            return;
        }
        if (count($answer->fileIds) !== 1) {
            $agent->sendActionFail($answer->acceptKey, $this->action, $requestId, self::NOT_KEPT);

            return;
        }

        $fileId = $answer->fileIds[0];
        $agent->sendActionSuccess(
            $answer->acceptKey,
            $this->action,
            $requestId,
            new CategoryPicturePublishReplyDTO($fileId, HilosFiles::downloadPath($fileId, CatalogPictureUploadTarget::VARIANT_SM)),
        );
    }

    /**
     * @param string $acceptKey Accept key of the connection
     * @param string $clientUploadId Id the browser gave the upload
     * @return string Key of the waiting request
     */
    private static function key(string $acceptKey, string $clientUploadId): string
    {
        return $acceptKey . self::KEY_SEPARATOR . $clientUploadId;
    }
}
