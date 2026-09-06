<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Database\ChatDbContext;
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
     * The chat-admin tables the Hilos index pages write through, and the verifier circle.
     *
     * The circle is the framework index agent's claim, made here because only a project knows
     * whether it declared {@see HilosFeature::BACKUP} at all - the table is created by that
     * feature's migration alone, and a class constant has no way to ask. This is the one demo
     * that declares it, so this is the one place the entry is legal.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        ChatDbContext::users => TruthSourceOperation::BY_KIND,
        ChatDbContext::events => TruthSourceOperation::BY_KIND,
        ChatDbContext::eventUserRenames => TruthSourceOperation::BY_KIND,
        HilosDbContext::verifierCircle => TruthSourceOperation::BY_KIND,
    ];
}
