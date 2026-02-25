<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Tables\Bot\Actions\BotItemActions;
use Demo\Chat\Tables\Bot\Actions\BotsTableActions;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Bots table definition with create / update / delete actions.
 *
 * @property-read BotsTableActions $actions
 */
class BotsTable extends TableDefinition
{
    protected function init(): void
    {
        $this->setActionsClass(BotsTableActions::class);
        $this->setItemActionsClass(BotItemActions::class);
    }
}
