<?php

declare(strict_types=1);

namespace MomentFixtures;

use Hilos\Utils\Helpers\TimeHelper;
use MomentFixtures\EventEntity;
use MomentFixtures\EventObject;
use MomentFixtures\ReadOnlyObject;
use MomentFixtures\RealProperty;
use MomentFixtures\TransformedObject;
use MomentFixtures\UnknownMapping;

class ProtectedProperty extends EventObject
{
    protected int $happenedAt = 0;

    public function own(ProtectedProperty $object): void
    {
        $this->happenedAt = time();
        $object->happenedAt = time();
    }
}

class PrivateProperty extends EventObject
{
    private int $happenedAt = 0;

    public function own(PrivateProperty $object, PrivateChild $child): void
    {
        $this->happenedAt = time();
        $object->happenedAt = time();
        $child->happenedAt = time();
    }
}

class ProtectedChild extends ProtectedProperty
{
    public function inherited(ProtectedProperty $object): void
    {
        $this->happenedAt = time();
        $object->happenedAt = time();
    }
}

class PrivateChild extends PrivateProperty
{
    public function inherited(PrivateProperty $object): void
    {
        $this->happenedAt = time();
        $object->happenedAt = time();
    }
}

trait ProtectedSlot
{
    protected int $happenedAt = 0;
}

trait NestedSlot
{
    use ProtectedSlot;
}

trait PrivateSlot
{
    private int $happenedAt = 0;

    public function traitOwn(TraitPrivate $object): void
    {
        $this->happenedAt = time();
        $object->happenedAt = time();
    }
}

trait PublicSlot
{
    public int $happenedAt = 0;
}

trait DirectSetter
{
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'happenedAt' => $this->entity->at = (int)$value,
            default => null,
        };
    }
}

class TraitProtected
{
    use NestedSlot;
    use DirectSetter;

    public const string ENTITY_CLASS = EventEntity::class;

    public function own(TraitProtected $object): void
    {
        $this->happenedAt = time();
        $object->happenedAt = time();
    }
}

class TraitPrivate extends EventObject
{
    use PrivateSlot;
}

class TraitPublic extends EventObject
{
    use PublicSlot;
}

class TraitChild extends TraitProtected
{
    public function inherited(): void
    {
        $this->happenedAt = time();
    }
}

class TraitPrivateChild extends TraitPrivate
{
    public function inherited(): void
    {
        $this->happenedAt = time();
    }
}

class PromotedPrivate extends EventObject
{
    public function __construct(private int $happenedAt = 0)
    {
    }

    public function own(): void
    {
        $this->happenedAt = time();
    }
}

class PromotedPublic extends EventObject
{
    public function __construct(public int $happenedAt = 0)
    {
    }
}

class TransformedPrivate extends TransformedObject
{
    private int $when = 0;
}

class UnknownPrivate extends UnknownMapping
{
    private int $happenedAt = 0;
}

class GetterPrivate extends ReadOnlyObject
{
    private int $happenedAt = 0;
}

class AmbiguousPrivate
{
    public const string ENTITY_CLASS = EventEntity::class;

    private int $happenedAt = 0;

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'happenedAt' => $this->entity->at = (int)$value,
            'happenedAt' => $this->entity->created_at = (int)$value,
            default => null,
        };
    }
}

class VisibilityWriter
{
    private PrivateProperty $object;

    public function native(ProtectedProperty $protected, PrivateProperty $private): void
    {
        $protected->happenedAt = time();
        $private->happenedAt = time();
        $private->happenedAt = time();
    }

    public function constructed(): void
    {
        $protected = new ProtectedProperty();
        $protected->happenedAt = time();
        $private = new PrivateProperty();
        $private->happenedAt = time();
        $this->object->happenedAt = time();
    }

    public function inherited(ProtectedChild $protected, PrivateChild $private): void
    {
        $protected->happenedAt = time();
        $private->happenedAt = time();
    }

    public function traits(TraitProtected $protected, TraitPrivate $private, TraitChild $child, TraitPrivateChild $privateChild): void
    {
        $protected->happenedAt = time();
        $private->happenedAt = time();
        $child->happenedAt = time();
        $privateChild->happenedAt = time();
    }

    public function promoted(PromotedPrivate $private, PromotedPublic $public): void
    {
        $private->happenedAt = time();
        $public->happenedAt = time();
    }

    public function valid(ProtectedProperty $protected, PrivateProperty $private): void
    {
        $protected->happenedAt = TimeHelper::nowMs();
        $private->happenedAt = 0;
    }

    public function publicProperties(RealProperty $real, TraitPublic $trait): void
    {
        $real->happenedAt = time();
        $trait->happenedAt = time();
    }

    public function unknown(TransformedPrivate $transformed, UnknownPrivate $unknown, GetterPrivate $getter, AmbiguousPrivate $ambiguous): void
    {
        $transformed->when = time();
        $unknown->happenedAt = time();
        $getter->happenedAt = time();
        $ambiguous->happenedAt = time();
    }

    public function unknownReceiver($object): void
    {
        $object->happenedAt = time();
    }
}

class DirectProtected
{
    public const string ENTITY_CLASS = EventEntity::class;

    protected int $happenedAt = 0;

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'happenedAt' => $this->entity->at = (int)$value,
            default => null,
        };
    }
}

class DirectPrivate
{
    public const string ENTITY_CLASS = EventEntity::class;

    private int $happenedAt = 0;

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            'happenedAt' => $this->entity->at = (int)$value,
            default => null,
        };
    }
}

trait VisitingWriter
{
    public function visit(DirectPrivate $object): void
    {
        $object->happenedAt = time();
    }
}

class FirstVisitor
{
    use VisitingWriter;
}

class SecondVisitor
{
    use VisitingWriter;
}

class ProtectedPeer extends ProtectedProperty
{
    public function sibling(ProtectedChild $object): void
    {
        $object->happenedAt = time();
    }
}

class PrivateScopeParent extends EventObject
{
    private int $happenedAt = 0;

    public function child(PrivateScopeChild $object): void
    {
        $object->happenedAt = time();
    }
}

class PrivateScopeChild extends PrivateScopeParent
{
    private int $happenedAt = 0;
}

class DirectVisibilityWriter
{
    public function write(DirectProtected $protected, DirectPrivate $private, PrivateScopeChild $child): void
    {
        $protected->happenedAt = time();
        $private->happenedAt = time();
        $child->happenedAt = time();
    }
}

trait UnusedWriter
{
    public function write(EventEntity $entity, EventObject $magic, DirectPrivate $unknownScope): void
    {
        $entity->at = time();
        $magic->happenedAt = time();
        $unknownScope->happenedAt = time();
    }
}
