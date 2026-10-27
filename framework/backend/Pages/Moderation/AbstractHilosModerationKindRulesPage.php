<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Moderation\DTO\ModerationRuleAddSignalData;
use Hilos\Pages\Moderation\DTO\HilosModerationKindSubscribeParams;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleAddActionDTO;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationKindTableRow;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Tables\Moderation\HilosModerationRuleTableRow;

/** Own rules and kind metadata, with an ADMIN gatekeeper for the rules writer. */
abstract class AbstractHilosModerationKindRulesPage extends AbstractHilosPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = HilosPageConstants::HILOS_MODERATION_KIND_RULES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [
        HilosSignalConstants::HILOS_MODERATION_RULE_ADD => HilosModerationRuleAddActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND_RULES,
    ];

    /**
     * @param string $acceptKey Initiating connection
     * @param string $action Browser action name
     * @param ActionPayloadDTO $dto Typed action payload
     * @return ?ActionReplyDTO Deferred until the writer answers
     * @throws AgentUnknownActionException When the action is not declared here
     * @throws InvalidActionPayloadException When the action has the wrong DTO
     * @throws ValidationException When the kind is no longer published
     * @throws InvalidArgumentException When the directory is invalid or forwarding fails
     * @throws InvalidFormatException When handover metadata is invalid
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::HILOS_MODERATION_RULE_ADD:
                if (!$dto instanceof HilosModerationRuleAddActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosModerationRuleAddActionDTO::class, $dto);
                }
                $this->handleAdd($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * @param AgentSignalData $data Writer's wrapped answer
     * @param string $sender Sending agent identity (unused)
     * @param string $name Page-owned reply name
     * @throws AgentUnknownSignalException When the reply name is unknown
     * @throws InvalidAgentSignalPayloadException When the reply has the wrong DTO
     * @throws InvalidArgumentException When the action answer cannot be queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE:
                if (!$data->data instanceof HandoverAnswerSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, HandoverAnswerSignalData::class, $data->data);
                }
                $this->answerHandover($data->data);

                break;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Revalidates the new kind and answers with both windows and the page identity.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When admission or the page response fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Canonicalizes metadata to the URL and restores only a rules window for that same kind.
     * Consumes the reported map before the browser can apply unrelated windows over these two.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When kind is missing or empty
     * @throws PageResourceNotFoundException When the directory does not publish the kind
     * @throws InvalidArgumentException When the project's kind directory is malformed
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = HilosModerationKindSubscribeParams::fromPageRouteParams($params);
        if (!isset(Hilos::moderationKinds()[$address->kind])) {
            throw new PageResourceNotFoundException('Unknown moderation kind: ' . $address->kind);
        }

        $reported = Hilos::$sr?->takeReportedTableWindows($acceptKey) ?? [];
        $rulesWindow = $reported[HilosModerationRulesTable::TABLE]
            ?? Hilos::$sr?->getTableViewport($acceptKey, HilosModerationRulesTable::TABLE);
        if (($rulesWindow?->filter[HilosModerationRulesTable::FILTER_KIND] ?? null) !== $address->kind) {
            $rulesWindow = null;
        }

        Hilos::$sr?->setTableViewport($acceptKey, new TableViewportSubscription(
            tableKey: HilosModerationKindsTable::TABLE,
            filter: [HilosModerationKindsTable::FILTER_KIND => $address->kind],
            limit: 1,
            rendered: [
                HilosModerationKindTableRow::kind,
                HilosModerationKindTableRow::title,
                HilosModerationKindTableRow::owner,
                HilosModerationKindTableRow::input,
                HilosModerationKindTableRow::model,
                HilosModerationKindTableRow::ownRuleCount,
                HilosModerationKindTableRow::profile,
                HilosModerationKindTableRow::reasons,
                HilosModerationKindTableRow::defaultRules,
            ],
        ));

        $rulesTable = new HilosModerationRulesTable();
        Hilos::$sr?->setTableViewport($acceptKey, new TableViewportSubscription(
            tableKey: HilosModerationRulesTable::TABLE,
            filter: array_replace($rulesWindow?->filter ?? [], [HilosModerationRulesTable::FILTER_KIND => $address->kind]),
            sort: $rulesWindow === null ? $rulesTable->defaultSort() : $rulesWindow->sort,
            limit: $rulesWindow?->limit ?? $rulesTable->windowSize(),
            anchor: $rulesWindow?->anchor,
            anchorDirection: $rulesWindow?->anchorDirection ?? TableAnchorDirection::After,
            pageIndex: $rulesWindow?->pageIndex,
            rendered: $rulesWindow?->rendered ?? [
                HilosModerationRuleTableRow::id,
                HilosModerationRuleTableRow::kind,
                HilosModerationRuleTableRow::rule,
            ],
        ));
    }

    /**
     * @param string $acceptKey Initiating connection
     * @param HilosModerationRuleAddActionDTO $dto Published catalog key and raw rule
     * @throws ValidationException When the kind is no longer published
     * @throws InvalidArgumentException When the directory is invalid or forwarding fails
     * @throws InvalidFormatException When handover metadata is invalid
     */
    private function handleAdd(string $acceptKey, HilosModerationRuleAddActionDTO $dto): void
    {
        $kind = Hilos::moderationKinds()[$dto->kind] ?? null;
        if ($kind === null) {
            throw new ValidationException("Unknown moderation kind: {$dto->kind}");
        }

        $this->forward(HilosSignalConstants::MODERATION_RULE_ADD, new ModerationRuleAddSignalData(
            kind: $dto->kind,
            rule: $dto->rule,
            replySignal: HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE,
            acceptKey: $acceptKey,
            requestId: $this->currentActionRequestId(),
            action: HilosSignalConstants::HILOS_MODERATION_RULE_ADD,
            successMessage: 'Rule added to "' . $kind->title . '".',
        ));
    }
}
