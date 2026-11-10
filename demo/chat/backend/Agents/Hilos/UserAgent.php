<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\UserRenameFeedLinkSignalData;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
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
    ];

    /** Person writes and self-stop arrive in frames; the worker tracks the idle window. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Writes the feed link without an answer; inherited person frames keep their own protocol.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity, passed to the inherited handler
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is unknown to this agent
     * @throws InvalidAgentSignalPayloadException When the payload differs from its declaration
     * @throws AgentException When the frame names another person
     * @throws InvalidArgumentException When an inherited answer cannot be named or queued
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

            default:
                parent::onSignalAgent($data, $sender, $name);

                return;
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
