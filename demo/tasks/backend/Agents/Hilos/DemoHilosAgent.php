<?php

declare(strict_types=1);

namespace Demo\Tasks\Agents\Hilos;

use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the tasks demo.
 *
 * Owns the framework Hilos admin pages (dashboard, settings, users, backup).
 *
 * The admin grant seam is NOT here any more (HIL-729): the command moved to the
 * sessions library, because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * The verifier circle.
     *
     * The circle is the framework index agent's claim, made here because only a project knows
     * whether it declared {@see HilosFeature::BACKUP} at all - the table is created by that
     * feature's migration alone, and a class constant has no way to ask. This demo declares it
     * (HIL-911), so the entry is legal here.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::verifierCircle => TruthSourceOperation::BY_KIND,
    ];
}
