<?php

declare(strict_types=1);

namespace Hilos\I18n;

/** The values of hilos_locale.first_weekday; Sunday is 0, as DateTimePickerWeekday. */
enum Weekday: int
{
    case SUNDAY = 0;
    case MONDAY = 1;
    case TUESDAY = 2;
    case WEDNESDAY = 3;
    case THURSDAY = 4;
    case FRIDAY = 5;
    case SATURDAY = 6;
}
