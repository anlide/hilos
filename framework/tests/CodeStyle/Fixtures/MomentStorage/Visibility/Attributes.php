<?php

declare(strict_types=1);

namespace MomentFixtures;

use Attribute;
use MomentFixtures\EventEntity;
use MomentFixtures\EventObject;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class MomentSlot
{
    public function __construct(array $values = [], mixed $nested = null)
    {
    }
}

class AttributedPublic extends EventObject
{
    public function __construct(#[MomentSlot] public int $happenedAt = 0)
    {
    }

    public function own(): void
    {
        $this->happenedAt = time();
    }
}

class AttributedPrivate extends EventObject
{
    public function __construct(#[MomentSlot] private int $happenedAt = 0)
    {
    }

    public function own(): void
    {
        $this->happenedAt = time();
    }
}

class AttributedProtected extends EventObject
{
    public function __construct(
        #[MomentSlot(values: ['first', ['nested', 'array']], nested: new MomentSlot(['inner', 'values']))]
        #[MomentSlot, MomentSlot(['another', 'attribute'])]
        protected int $happenedAt = 0,
        #[MomentSlot(['neighbor', 'array'])] public int $neighbor = 0,
    ) {
    }

    public function own(): void
    {
        $this->happenedAt = time();
    }
}

class AttributedChild extends AttributedProtected
{
    public function inherited(AttributedProtected $protected, AttributedPrivate $private): void
    {
        $this->happenedAt = time();
        $protected->happenedAt = time();
        $private->happenedAt = time();
    }
}

class AttributedLaterPublic extends EventObject
{
    public function __construct(
        #[MomentSlot(['first', 'parameter'])] private int $neighbor = 0,
        #[MomentSlot(nested: new MomentSlot(['a', 'b']))] #[MomentSlot]
        public int $happenedAt = 0,
        #[MomentSlot(['last', ['parameter']])] protected int $last = 0,
    ) {
    }
}

class AttributedLaterPrivate extends EventObject
{
    public function __construct(
        #[MomentSlot(['first', 'parameter'])] public int $neighbor = 0,
        #[MomentSlot(nested: new MomentSlot(['a', 'b']))] private int $happenedAt = 0,
    ) {
    }

    public function own(): void
    {
        $this->happenedAt = time();
    }
}

class AttributedWriter
{
    public function publicOnly(AttributedPublic $public, AttributedLaterPublic $later): void
    {
        $public->happenedAt = time();
        $later->happenedAt = time();
    }

    public function inaccessible(AttributedPrivate $private, AttributedProtected $protected, AttributedLaterPrivate $later): void
    {
        $private->happenedAt = time();
        $protected->happenedAt = time();
        $later->happenedAt = time();
    }

    public function subsequent(EventEntity $entity): void
    {
        $entity->at = time();
    }
}
