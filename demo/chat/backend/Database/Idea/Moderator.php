<?php

namespace Demo\Chat\Database\Idea;

use Demo\Chat\Database\Object\Moderator as ObjectModerator;
use Hilos\Database\Idea\IdeaItem;
use RuntimeException;

/**
 * Moderator Idea
 * High-level abstraction with lazy loading and relationships
 *
 * Stores reference to ObjectModerator instance.
 * Object instances are stored in ObjectCollection in Idea.
 *
 * @extends IdeaItem<ObjectModerator>
 *
 * @property-read ?int $idModerator Moderator ID (primary key)
 * @property-read string $name Moderator name
 * @property-read bool $checkAdultContent Check for adult content
 * @property-read bool $checkViolence Check for violence and war topics
 * @property-read bool $checkProfanity Check for profanity
 * @property-read bool $checkSpam Check for spam
 * @property-read bool $checkHateSpeech Check for hate speech
 * @property-read int $sensitivityLevel Sensitivity level 1-10 (1=lenient, 10=strict)
 * @property-read ?string $additionalRules JSON array of additional moderation rules
 * @property-read bool $active Whether moderator is active
 * @property-read string $createdAt Creation timestamp
 * @property-read string $updatedAt Last update timestamp
 */
final class Moderator extends IdeaItem
{
    /**
     * Public constructor - creates IdeaModerator from ObjectModerator instance
     *
     * @param ObjectModerator $objectModerator ObjectModerator instance (reference)
     */
    public function __construct(ObjectModerator &$objectModerator)
    {
        parent::__construct($objectModerator);
    }

    /**
     * Property getter (read-only access)
     * Provides access to ObjectModerator properties through IdeaModerator interface.
     *
     * @param string $name Property name
     * @return int|string|bool|null Property value
     * @throws RuntimeException If property does not exist
     */
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

    /**
     * Convert to array representation
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @return array<string, mixed> Array representation
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
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
