<?php

declare(strict_types=1);

namespace Hilos\Moderation\Library;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\View\Collection\ModerationRules;
use Hilos\Hilos;
use Hilos\Moderation\DTO\ModerationRuleAddSignalData;
use Hilos\WiringRefusal;
use Throwable;

/**
 * ModerationRulesLibraryAgent - the one writer of administrators' own moderation rules (HIL-1624).
 *
 * Owns `hilos_moderation_rule` whole: one holder for the whole cluster, placed by the policy, and
 * nobody per kind or per rule. A rule belongs to the installation rather than to a person or an
 * event, so there is no set to cut the table by, and the kind is a column of the row, not an owner
 * of it. Reading does not come here: a reader declares the table among its reads and asks
 * {@see ModerationRules::effectiveForKind()}, which answers from the database without waking the
 * holder (docs/agents/architecture/entity-libraries.md, "Reading Without The Owner").
 *
 * Concrete and registered without a project subclass, as the settings and i18n libraries are: the
 * table, its ORM and the kind directory are the framework's, and nothing here waits for a project
 * hook. The Rules page hands additions here; edit and delete asks arrive with their dialogs
 * (HIL-1633, HIL-1634).
 */
final class ModerationRulesLibraryAgent extends AbstractHilosAgent
{
    /**
     * The rules table, whole and for every operation.
     *
     * Claimed outright rather than by set or by key: adding a rule, editing its text and removing
     * it all arrive here, whatever the kind.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::moderationRules => TruthSourceOperation::ALL,
    ];

    public const string AGENT_TYPE = HilosAgentType::HILOS_MODERATION_RULES_LIBRARY;

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::MODERATION_RULE_ADD => ModerationRuleAddSignalData::class,
    ];

    /**
     * @param AgentSignalData $data Gatekeeper's wrapped ask
     * @param string $sender Sending agent identity (unused)
     * @param string $name Declared write signal
     * @throws AgentUnknownSignalException When the signal name is unknown
     * @throws InvalidAgentSignalPayloadException When the ask has the wrong DTO
     * @throws InvalidArgumentException When the answer cannot be queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::MODERATION_RULE_ADD:
                if (!$data->data instanceof ModerationRuleAddSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ModerationRuleAddSignalData::class, $data->data);
                }
                $this->handleAdd($data->data);

                break;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param ModerationRuleAddSignalData $ask Kind, raw rule and gatekeeper correlation
     * @throws InvalidArgumentException When the answer cannot be queued
     */
    private function handleAdd(ModerationRuleAddSignalData $ask): void
    {
        $refusal = null;
        try {
            Hilos::$db->moderationRules->actions->create($ask->kind, $ask->rule);
        } catch (WiringRefusal $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
        } catch (Throwable $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
        }
        if ($refusal?->isInternal()) {
            $this->logAgentError('Adding a moderation rule failed: ' . $refusal->errorDetail);
        }

        $this->sendToAgent($ask->replySignal, HandoverAnswerSignalData::to($ask, $refusal));
    }
}
