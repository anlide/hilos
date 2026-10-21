<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Database\Object\Collection;

class CollectionSqlReadLookAlikes extends BaseCollection
{
    /** static::entityClass()::get() and self::entityClass()::count() are prose. */
    public function reads(): void
    {
        static::entityClass()::get(); // fresh-sql: The transaction must read rows absent from this mirror.
        self::entityClass()::count(); // fresh-sql: The transaction must count rows absent from this mirror.
        parent // fresh-sql: The transaction must read rows absent from this mirror.
            ::entityClass()
            ::get();
        static::entityClass()::get(); self::entityClass()::count(); // fresh-sql: Both queries include rows absent from this mirror.
        $this->loadByColumn('id', 1);
        $this->countByColumn('id', 1);
        parent::loadByColumn('id', 1);
        parent::countByColumn('id', 1);
        static::entityClass()::getById(1);
        static::entityClass()::getEntities();
        static::entityClass()::countUpTo(1);
        static::entityClass()::customRead();
        static::entityClass()::class;
        static::entityClass()::get;
        self::entityClass()::count;
        static::otherClass()::get();
        $this->entityClass()::get();
        $single = 'static::entityClass()::get()';
        $double = "self::entityClass()::count()";
        $interpolated = "parent::entityClass()::get() $single";
        $heredoc = <<<TEXT
            static::entityClass()::get() $single
            // fresh-sql: Text is not a code comment.
            TEXT;
        $nowdoc = <<<'TEXT'
            self::entityClass()::count()
            TEXT;
        // static::entityClass()::get();
        /* self::entityClass()::count(); */

        // These forms are deliberately outside the lexical detector.
        $entityClass = static::entityClass();
        $entityClass::get();
        EntityAlias::count();
        Database::sql('SELECT 1');
        $this->readThroughHelper();
    }
}

class BaseCollection
{
}

class EntityAlias
{
}

class Database
{
}
