<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\ModerationRules as ObjectModerationRules;
use Hilos\Database\Object\Item\ModerationRule as ObjectModerationRule;
use Hilos\Database\View\Collection\ModerationRules as DbCollectionModerationRules;
use Hilos\Database\View\Item\ModerationRule;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends DbActions<ModerationRule, ObjectModerationRules>
 * @property-read DbCollectionModerationRules $collection
 * @property-read ObjectModerationRules $objectCollection
 */
class ModerationRulesActions extends DbActions
{
    /**
     * Adds an administrator's own rule to a kind; the kind's first own rule replaces its
     * built-in rules whole.
     *
     * @param string $kind Moderation kind key the directory publishes
     * @param string $rule Rule text, stored as given
     * @return ModerationRule New rule
     * @throws ValidationException When the directory does not publish the kind, or the text is empty, only
     *     whitespace, or longer than the column holds
     * @throws InvalidArgumentException When the project's moderation kind directory is malformed
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function create(string $kind, string $rule): ModerationRule
    {
        $this->ensureCanCreate();

        if (!isset(Hilos::moderationKinds()[$kind])) {
            throw new ValidationException("Unknown moderation kind: {$kind}");
        }
        ObjectModerationRule::assertValidRule($rule);

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $moderationRule = $objectClass::create();
        $moderationRule->kind = $kind;
        $moderationRule->rule = $rule;
        $moderationRule->sync();
        $this->addObjectToCollection($moderationRule);

        return $this->createDbItemFromObject($moderationRule);
    }
}
