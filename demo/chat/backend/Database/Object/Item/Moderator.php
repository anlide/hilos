<?php

namespace Demo\Chat\Database\Object\Item;

use Hilos\Database\Object\Item\Object_;
use Demo\Chat\Database\Entity\Item\Moderator as EntityModerator;

/**
 * Moderator Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\Moderator
 */
final class Moderator extends Object_
{
    public const string idModerator = 'idModerator';
    public const string name = 'name';
    public const string checkAdultContent = 'checkAdultContent';
    public const string checkViolence = 'checkViolence';
    public const string checkProfanity = 'checkProfanity';
    public const string checkSpam = 'checkSpam';
    public const string checkHateSpeech = 'checkHateSpeech';
    public const string sensitivityLevel = 'sensitivityLevel';
    public const string additionalRules = 'additionalRules';
    public const string active = 'active';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';

    protected EntityModerator $entity;
    protected EntityModerator $entitySync;

    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityModerator::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    public static function fromEntity(EntityModerator $entity): self
    {
        $obj = new self();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

    public function __get(string $property): mixed
    {
        return match ($property) {
            self::idModerator => $this->entity->id_moderator,
            self::name => $this->entity->name,
            self::checkAdultContent => $this->entity->check_adult_content,
            self::checkViolence => $this->entity->check_violence,
            self::checkProfanity => $this->entity->check_profanity,
            self::checkSpam => $this->entity->check_spam,
            self::checkHateSpeech => $this->entity->check_hate_speech,
            self::sensitivityLevel => $this->entity->sensitivity_level,
            self::additionalRules => $this->entity->additional_rules,
            self::active => $this->entity->active,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            default => parent::__get($property),
        };
    }

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::checkAdultContent => $this->entity->check_adult_content = (bool)$value,
            self::checkViolence => $this->entity->check_violence = (bool)$value,
            self::checkProfanity => $this->entity->check_profanity = (bool)$value,
            self::checkSpam => $this->entity->check_spam = (bool)$value,
            self::checkHateSpeech => $this->entity->check_hate_speech = (bool)$value,
            self::sensitivityLevel => $this->entity->sensitivity_level = (int)$value,
            self::additionalRules => $this->entity->additional_rules = (string)$value,
            self::active => $this->entity->active = (bool)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            self::updatedAt => $this->entity->updated_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    public function toArray(): array
    {
        return [
            self::idModerator => $this->entity->id_moderator,
            self::name => $this->entity->name,
            self::checkAdultContent => $this->entity->check_adult_content,
            self::checkViolence => $this->entity->check_violence,
            self::checkProfanity => $this->entity->check_profanity,
            self::checkSpam => $this->entity->check_spam,
            self::checkHateSpeech => $this->entity->check_hate_speech,
            self::sensitivityLevel => $this->entity->sensitivity_level,
            self::additionalRules => $this->entity->additional_rules,
            self::active => $this->entity->active,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
        ];
    }
}
