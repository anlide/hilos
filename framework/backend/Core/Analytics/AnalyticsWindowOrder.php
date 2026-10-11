<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** Canonical order of an analytics window, with row numbers breaking time ties. */
enum AnalyticsWindowOrder: string
{
    case TIME_ASC = 'time_asc';
    case TIME_DESC = 'time_desc';
    case NUMBER = 'number';
}
