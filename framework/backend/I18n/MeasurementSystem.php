<?php

declare(strict_types=1);

namespace Hilos\I18n;

/** The values of hilos_locale.measurement_system. */
enum MeasurementSystem: string
{
    case METRIC = 'metric';
    case IMPERIAL = 'imperial';
}
