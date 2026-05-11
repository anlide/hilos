<?php

declare(strict_types=1);

namespace Demo\Chat\Projection\Page;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\Settings\SettingsCatalog;
use Demo\Chat\Tables\Settings\DTO\SettingsTableSnapshotDTO;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\Rule\TableRule;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Projects framework settings table state through the chat demo signal contract.
 */
final class HilosSettingsPageProjection extends PageProjection
{
    /**
     * Returns the framework settings page key.
     *
     * @return string Page key for the Hilos settings route
     */
    public function page(): string
    {
        return HilosPageConstants::HILOS_SETTINGS;
    }

    /**
     * Returns the subscribe snapshot signal for the settings page.
     *
     * @return string Signal name for the initial settings table snapshot
     */
    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS;
    }

    /**
     * Registers settings table mutation projection for this page.
     *
     * @return iterable<TableRule>
     */
    protected function rules(): iterable
    {
        yield new TableRule(
            tableKey: TableChatContext::settings,
            triggers: [HilosDbContext::settings],
            wireSignalName: ChatSignalConstants::TABLE_MUTATION,
        );
    }

    /**
     * Wraps the settings table snapshot with settings catalog key order.
     *
     * @param SubscribeSnapshotAccumulator $accumulator Accumulated table snapshot state
     * @param string $acceptKey Unused subscriber accept key; this snapshot is not connection-local
     * @param PageRouteParams $params Unused route params; this page has no params
     * @return ?SignalDataInterface Settings subscribe payload, or null when the table snapshot is unavailable
     */
    protected function wrapSnapshot(
        SubscribeSnapshotAccumulator $accumulator,
        string $acceptKey,
        PageRouteParams $params,
    ): ?SignalDataInterface {
        $snapshot = $accumulator->getTableSnapshot(TableChatContext::settings);
        if ($snapshot === null) {
            return null;
        }

        return new ChatEventSignalDTO(
            entities: new EntitiesChangesDTO(),
            tables: [
                TableChatContext::settings => new SettingsTableSnapshotDTO(
                    $snapshot,
                    array_keys(SettingsCatalog::getCatalog()),
                ),
            ],
        );
    }
}
