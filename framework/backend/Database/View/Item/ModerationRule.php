<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\ModerationRuleActions;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\HilosException;

/**
 * Read-facing moderation rule row.
 *
 * @extends DbItem<ObjectModerationRule>
 * @property-read ModerationRuleActions $actions
 * @property-read ?int $id
 * @property-read string $kind
 * @property-read string $rule
 */
class ModerationRule extends DbItem
{
    /**
     * @param string $name Scalar property or actions name
     * @return mixed Field value or the inherited item member
     * @throws PropertyNotFoundException When the property is unknown
     * @throws ActionsClassException When item actions are not configured
     * @throws HilosException When the inherited getter refuses the member
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectModerationRule::id => $this->_object->id,
            ObjectModerationRule::kind => $this->_object->kind,
            ObjectModerationRule::rule => $this->_object->rule,
            default => parent::__get($name),
        };
    }
}
