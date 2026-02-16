<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Item\Moderator as ObjectModerator;
use Hilos\Hilos\Database\Item\DbItem;
use RuntimeException;

/**
 * Moderator Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectModerator>
 */
final class Moderator extends DbItem
{
    public function __construct(ObjectModerator &$objectModerator)
    {
        parent::__construct($objectModerator);
    }

    public function __get(string $name): int|string|bool|null
    {
        return match ($name) {
            ObjectModerator::idModerator => $this->_object->idModerator,
            ObjectModerator::name => $this->_object->name,
            ObjectModerator::checkAdultContent => $this->_object->checkAdultContent,
            ObjectModerator::checkViolence => $this->_object->checkViolence,
            ObjectModerator::checkProfanity => $this->_object->checkProfanity,
            ObjectModerator::checkSpam => $this->_object->checkSpam,
            ObjectModerator::checkHateSpeech => $this->_object->checkHateSpeech,
            ObjectModerator::sensitivityLevel => $this->_object->sensitivityLevel,
            ObjectModerator::additionalRules => $this->_object->additionalRules,
            ObjectModerator::active => $this->_object->active,
            ObjectModerator::createdAt => $this->_object->createdAt,
            ObjectModerator::updatedAt => $this->_object->updatedAt,
            default => parent::__get($name),
        };
    }

    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $data = [];
        if ($withId) {
            $data[ObjectModerator::idModerator] = $this->_object->idModerator;
        }
        $data[ObjectModerator::name] = $this->_object->name;
        $data[ObjectModerator::checkAdultContent] = $this->_object->checkAdultContent;
        $data[ObjectModerator::checkViolence] = $this->_object->checkViolence;
        $data[ObjectModerator::checkProfanity] = $this->_object->checkProfanity;
        $data[ObjectModerator::checkSpam] = $this->_object->checkSpam;
        $data[ObjectModerator::checkHateSpeech] = $this->_object->checkHateSpeech;
        $data[ObjectModerator::sensitivityLevel] = $this->_object->sensitivityLevel;
        $data[ObjectModerator::additionalRules] = $this->_object->additionalRules;
        $data[ObjectModerator::active] = $this->_object->active;
        $data[ObjectModerator::createdAt] = $this->_object->createdAt;
        $data[ObjectModerator::updatedAt] = $this->_object->updatedAt;
        return $data;
    }
}
