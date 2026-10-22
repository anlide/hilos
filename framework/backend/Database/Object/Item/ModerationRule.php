<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ModerationRule as EntityModerationRule;
use Hilos\Database\Object\Collection\ModerationRules as ObjectModerationRules;

/**
 * Scalar moderation rule row: one administrator's own rule text for one moderation kind.
 *
 * @extends Object_<EntityModerationRule>
 * @property-read ?int $id
 * @property string $kind
 * @property string $rule
 */
class ModerationRule extends Object_
{
    public const string ENTITY_CLASS = EntityModerationRule::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectModerationRules::class;
    public const string id = 'id';
    public const string kind = 'kind';
    public const string rule = 'rule';

    /** Longest rule text, in bytes: what the TEXT column holds. */
    public const int MAX_RULE_BYTES = 65535;

    /**
     * The one check of a rule text, shared by the doors that create and edit a rule. The text is
     * stored as given: it is not trimmed, only refused when nothing but whitespace is in it.
     *
     * @param string $rule Rule text a person wrote
     * @throws ValidationException When the text is empty, only whitespace, or longer than the column holds
     */
    public static function assertValidRule(string $rule): void
    {
        if (trim($rule) === '') {
            throw new ValidationException('A moderation rule must have text');
        }
        if (strlen($rule) > self::MAX_RULE_BYTES) {
            throw new ValidationException('A moderation rule must be at most ' . self::MAX_RULE_BYTES . ' bytes long');
        }
    }

    /**
     * @param string $property Scalar property name
     * @return mixed Stored field value
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::kind => $this->entity->kind,
            self::rule => $this->entity->rule,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Writable scalar property name
     * @param mixed $value Value to persist
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::kind => $this->entity->kind = (string)$value,
            self::rule => $this->entity->rule = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /** @return array<string, mixed> Scalar fields of the rule */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::kind => $this->entity->kind,
            self::rule => $this->entity->rule,
        ];
    }
}
