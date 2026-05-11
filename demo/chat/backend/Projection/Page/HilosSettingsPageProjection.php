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
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Projection\PageProjection;
use Hilos\Core\Projection\Rule\TableRule;
use Hilos\Core\Projection\SubscribeSnapshotAccumulator;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;

final class HilosSettingsPageProjection extends PageProjection
{
    public function page(): string
    {
        return HilosPageConstants::HILOS_SETTINGS;
    }

    public function subscribeSnapshotSignalName(): string
    {
        return ChatSignalConstants::SUBSCRIPTION_PAGE_HILOS_SETTINGS;
    }

    protected function rules(): iterable
    {
        yield new TableRule(
            tableKey: TableChatContext::settings,
            triggers: [HilosDbContext::settings],
            wireSignalName: ChatSignalConstants::TABLE_MUTATION,
        );
    }

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
