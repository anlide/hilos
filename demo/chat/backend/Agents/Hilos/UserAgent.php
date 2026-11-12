<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\UserRenameFeedLinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkSignalData;
use Demo\Chat\Core\Router\DTO\UserRenameFeedUnlinkedSignalData;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\WiringRefusal;
use Throwable;

/** Person agent bound to the chat project. */
final class UserAgent extends AbstractUserAgent
{
    /** @var array<string, mixed> Project frames addressed to the person's agent. */
    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        ChatSignalConstants::USER_RENAME_FEED_LINK => [
            AgentSignalConfigKey::INDEX_FIELD => UserRenameFeedLinkSignalData::userId,
            AgentSignalConfigKey::DTO => UserRenameFeedLinkSignalData::class,
        ],
        ChatSignalConstants::USER_RENAME_FEED_UNLINK => [
            AgentSignalConfigKey::INDEX_FIELD => UserRenameFeedUnlinkSignalData::userId,
            AgentSignalConfigKey::DTO => UserRenameFeedUnlinkSignalData::class,
        ],
    ];

    /** Person writes and self-stop arrive in frames; the worker tracks the idle window. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Answers the unlink; the link keeps no answer. Inherited frames keep their own protocol.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity, passed to the inherited handler
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is unknown to this agent
     * @throws InvalidAgentSignalPayloadException When the payload differs from its declaration
     * @throws AgentException When the frame names another person or its unlink answer cannot be built
     * @throws InvalidArgumentException When an inherited answer cannot be named or queued
     * @throws InvalidFormatException When an inherited renewal answer is both renewed and refused
     * @throws DatabaseException When the folded-account lookup fails
     * @throws LogicException When the folded-account collection cannot be read
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::USER_RENAME_FEED_LINK:
                $link = $data->data;
                if (!$link instanceof UserRenameFeedLinkSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserRenameFeedLinkSignalData::class, $link);
                }

                $this->refuseAnotherPerson($link->userId, $name);
                $this->applyFeedLink($link);
                if (isset(Hilos::$db->userMerges[$link->userId])) {
                    $this->selfStop();
                }

                return;

            case ChatSignalConstants::USER_RENAME_FEED_UNLINK:
                $unlink = $data->data;
                if (!$unlink instanceof UserRenameFeedUnlinkSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, UserRenameFeedUnlinkSignalData::class, $unlink);
                }

                $this->refuseAnotherPerson($unlink->userId, $name);
                $this->sendToAgent(ChatSignalConstants::USER_RENAME_FEED_UNLINKED, $this->unlinkFeedEvents($unlink));
                if (isset(Hilos::$db->userMerges[$unlink->userId])) {
                    $this->selfStop();
                }

                return;

            default:
                parent::onSignalAgent($data, $sender, $name);

                return;
        }
    }

    /**
     * Contains write failures in this agent's log and answers with the unlink outcome.
     *
     * @param UserRenameFeedUnlinkSignalData $unlink Person and history-clear attempt
     * @return UserRenameFeedUnlinkedSignalData Answer for the same attempt
     * @throws AgentException When the answer violates its payload contract
     */
    private function unlinkFeedEvents(UserRenameFeedUnlinkSignalData $unlink): UserRenameFeedUnlinkedSignalData
    {
        $unlinked = true;
        try {
            Hilos::$db->userRenames->actions->unlinkEventsOf($unlink->userId);
        } catch (WiringRefusal $e) {
            $this->logAgentError(
                "Rename feed unlink for user #{$unlink->userId}, clear {$unlink->clearId} refused by wiring: {$e->getMessage()}",
            );
            $unlinked = false;
        } catch (Throwable $e) {
            $this->logAgentError(
                "Rename feed unlink for user #{$unlink->userId}, clear {$unlink->clearId} not written: {$e->getMessage()}",
            );
            $unlinked = false;
        }

        try {
            return new UserRenameFeedUnlinkedSignalData($unlink->userId, $unlink->clearId, $unlinked);
        } catch (InvalidFormatException $e) {
            throw new AgentException('Rename feed unlink answer violates its payload contract', previous: $e);
        }
    }

    /**
     * A late or repeated frame is ordinary; write failures stay in this agent's log.
     *
     * @param UserRenameFeedLinkSignalData $link Captured journal row and feed event
     */
    private function applyFeedLink(UserRenameFeedLinkSignalData $link): void
    {
        try {
            if (isset(Hilos::$db->userRenames[$link->renameId])) {
                Hilos::$db->userRenames[$link->renameId]->actions->linkEvent($link->eventId);
            }
        } catch (WiringRefusal $e) {
            $this->logAgentError(
                "Rename feed link for user #{$link->userId}, rename #{$link->renameId}, event #{$link->eventId}"
                . " refused by wiring: {$e->getMessage()}",
            );
        } catch (Throwable $e) {
            $this->logAgentError(
                "Rename feed link for user #{$link->userId}, rename #{$link->renameId}, event #{$link->eventId}"
                . " not written: {$e->getMessage()}",
            );
        }
    }
}
