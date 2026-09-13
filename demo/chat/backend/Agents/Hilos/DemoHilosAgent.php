<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;

/**
 * DemoHilosAgent - Concrete Hilos index agent for chat demo.
 *
 * Handles Hilos dashboard, settings and i18n pages in the demo project.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * The verifier circle.
     *
     * The circle is the framework index agent's claim, made here because only a project knows
     * whether it declared {@see HilosFeature::BACKUP} at all - the table is created by that
     * feature's migration alone, and a class constant has no way to ask. This is the one demo
     * that declares it, so this is the one place the entry is legal.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::verifierCircle => TruthSourceOperation::BY_KIND,
    ];
}
