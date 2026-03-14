<?php

namespace Hilos\Database\Filter;

/**
 * Logic operators for combining filters.
 */
enum FilterLogic: string
{
    case AND = 'AND';
    case OR = 'OR';
    case XOR = 'XOR';
}

