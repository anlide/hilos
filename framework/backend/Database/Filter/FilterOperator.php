<?php

namespace Hilos\Database\Filter;

/**
 * Filter operators for column comparisons.
 */
enum FilterOperator: string
{
    case EQUALS = '=';
    case NOT_EQUALS = '!=';
    case GREATER = '>';
    case LESS = '<';
    case GREATER_OR_EQUAL = '>=';
    case LESS_OR_EQUAL = '<=';
    case IN = 'IN';
    case NOT_IN = 'NOT IN';
    case LIKE = 'LIKE';
    case NOT_LIKE = 'NOT LIKE';
    case IS_NULL = 'IS NULL';
    case IS_NOT_NULL = 'IS NOT NULL';
    case BETWEEN = 'BETWEEN';
}

