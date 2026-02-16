<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Item\Object_;

/**
 * Base class for custom filters
 * Extend this class to create custom filter logic
 */
abstract class CustomFilter implements FilterInterface
{
    // Разработчик переопределяет все методы
    // Может быть сложная логика: подзапросы, функции БД, etc.
}

