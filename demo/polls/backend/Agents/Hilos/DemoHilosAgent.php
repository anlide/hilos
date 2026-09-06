<?php

declare(strict_types=1);

namespace Demo\Polls\Agents\Hilos;

use Demo\Polls\Database\PollsDbContext;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;

/**
 * DemoHilosAgent - Concrete Hilos index agent for the polls demo.
 *
 * Owns the framework Hilos admin pages (dashboard, settings, users). Declares
 * the standalone user-rename audit collection as its own so the users page's
 * rename action — handled in this agent — may append audit rows.
 *
 * The admin grant seam is NOT here any more (HIL-729): the command moved to the
 * sessions library, because what it writes ends in a person's open tabs being told.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * The standalone rename-audit collection, which the users page's rename action appends to.
     *
     * The verifier circle is not here: this demo does not declare {@see HilosFeature::BACKUP}, so
     * the table its migration creates does not exist to claim.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [PollsDbContext::userRenames => TruthSourceOperation::BY_KIND];
}
