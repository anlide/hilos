<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Moderation\DTO\HilosModerationKindSubscribeParams;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationKindTableRow;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Tables\Moderation\HilosModerationRuleTableRow;

/** Read-only own rules and kind metadata in one subscription answer. */
abstract class AbstractHilosModerationKindRulesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MODERATION_KIND_RULES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND_RULES,
    ];

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
}
