<?php

declare(strict_types=1);

namespace MomentFixtures;

use Hilos\Utils\Helpers\TimeHelper;
use MomentFixtures\EventEntity;
use MomentFixtures\EventObject;
use stdClass;

class TernaryWriter
{
    private EventObject $object;

    public function falseArm(EventEntity $entity, bool $condition): void
    {
        $this->accept($condition ? 0 : /** The arm. */ $entity->at = time());
    }

    public function trueArm(EventEntity $entity, bool $condition): void
    {
        $this->accept($condition ? /** The arm. */ $entity->at = time() : 0);
    }

    public function parenthesized(EventEntity $entity, bool $condition): void
    {
        $this->accept(($condition ? (($entity)->at = (time())) : 0));
        $this->accept(($condition ? 0 : (($entity)->created_at = (time()))));
    }

    public function arrays(EventEntity $entity, bool $condition): void
    {
        $this->accept($condition ? $entity['at'] = time() : $entity['created_at'] = time());
        $this->accept($condition ? 0 : /** The arm. */ $entity['at'] = time());
    }

    public function objects(EventObject $object, bool $condition): void
    {
        $this->accept($condition ? $object->happenedAt = time() : $this->object->happenedAt = time());
        $this->accept($condition ? 0 : $object->misleadingAt = time());
    }

    public function nested(EventEntity $entity, bool $condition, bool $other): void
    {
        $this->accept($condition ? 0 : ($other ? $entity->at = time() : $entity->created_at = time()));
        $this->accept($condition ? ($other ? 0 : $entity->at = time()) : 0);
        $this->accept($condition ?: $entity->at = time());
    }

    public function neighbors(EventEntity $entity, bool $condition): void
    {
        $this->accept(first: $entity->at = time(), value: $condition ? 0 : $entity->created_at = time());
        $this->accept(['at' => $condition ? $entity->at = time() : 0, 'other' => $entity->duration = time()]);
        $this->accept(value: $condition ? $entity->at = time() : 0, last: $entity->created_at = time());
    }

    public function validTrue(EventEntity $entity, bool $condition): void
    {
        $this->accept($condition ? $entity->at = TimeHelper::nowMs() : 0);
    }

    public function validFalse(EventEntity $entity, bool $condition): void
    {
        $this->accept($condition ? 0 : /** The arm. */ $entity->created_at = null);
    }

    public function aliasTrue(EventEntity $entity, bool $condition): void
    {
        $when = time();
        $this->accept($condition ? $entity->at = $when : 0);
    }

    public function aliasFalse(EventEntity $entity, bool $condition): void
    {
        $when = time();
        $this->accept($condition ? 0 : /** The arm. */ $entity->at = $when);
    }

    public function branchAlias(EventEntity $entity, bool $condition): void
    {
        $this->accept($condition ? $when = time() : 0);
        $entity->at = $when;
        $this->accept($condition ? 0 : /** The arm. */ $other = time());
        $entity->created_at = $other;
    }

    public function unknown($entity, bool $condition): void
    {
        $this->accept($condition ? $entity->at = time() : $entity['at'] = time());
    }

    public function plain(stdClass $entity, bool $condition): void
    {
        $this->accept($condition ? 0 : /** The arm. */ $entity->at = time());
    }

    public function localReceiver(bool $condition): void
    {
        $entity = new EventEntity();
        $this->accept($condition ? 0 : /** The arm. */ $entity->at = time());
    }

    public function annotatedReceiver($unknown, bool $condition): void
    {
        /** @var EventEntity $entity */
        $entity = $unknown;
        $this->accept($condition ? $entity->at = time() : 0);
    }

    public function branchedReceiver(EventEntity $entity, bool $condition): void
    {
        $receiver = $condition ? $entity : $entity;
        $receiver->at = time();
        $entity[$condition ? 'at' : 'at'] = time();
    }

    public function branchedValue(EventEntity $entity, bool $condition): void
    {
        $entity->at = $condition ? time() : time();
        $entity->created_at = time() ? 0 : 1;
    }

    public function bracketRebinding(EventEntity $entity, stdClass $replacement, bool $condition): void
    {
        $this->accept($condition ? 0 : /** The arm. */ [$entity] = [$replacement]);
        $entity->at = time();
    }

    public function listRebinding(EventEntity $entity, stdClass $replacement, bool $condition): void
    {
        $this->accept($condition ? list($entity) = [$replacement] : 0);
        $entity->at = time();
    }
}
