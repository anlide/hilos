<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Backup\BackupScheduleEntry;
use Hilos\Core\Daemon\Cron as Cron;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Cron\CronRule as Schedule;
use Hilos\Core\Daemon\Cron\CronRuleOrigin;
use Hilos\Core\Daemon\Cron\CronRuleOrigin as Origin;

/**
 * Declarations CRON-NAMESPACE judges. One token stream is read under both policies:
 * framework/backend requires an explicit framework origin outside the three bridges, and a
 * project root refuses that origin. A comment and a string wear the same words and are not
 * declarations.
 */
final class CronNamespaceSamples
{
    /**
     * @param string $name Rule name
     * @param string $expression Cron expression
     * @param mixed $origin Computed origin
     * @param mixed $scope Schedule scope stand-in
     * @param mixed $mechanism Schedule mechanism stand-in
     * @param mixed $entry Schedule entry stand-in
     * @param array<int, mixed> $parts Arguments unpacked inside one parameter
     * @param array<int, mixed> $args Arguments unpacked into the construction
     */
    public function declarations(
        string $name,
        string $expression,
        mixed $origin,
        mixed $scope,
        mixed $mechanism,
        mixed $entry,
        array $parts,
        array $args,
    ): void {
        // Framework hit: origin omitted. Project allows the default.
        new CronRule($name, $expression);

        // Framework hit: explicit project, and this is not the project bridge. Project allows it.
        new Schedule($name, $expression, Origin::PROJECT);

        // Framework allows an explicit framework origin. Project refuses it.
        new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK);

        // Named arguments carry the same origin. Framework allows it, project refuses it.
        new CronRule(expression: $expression, name: $name, origin: Origin::FRAMEWORK);

        // Parentheses around the case do not change it. Framework allows it, project refuses it.
        new CronRule($name, $expression, (CronRuleOrigin::FRAMEWORK));

        // An earlier parameter that unpacks inside a call does not hide the origin.
        new CronRule($name, $this->listed(...$parts), CronRuleOrigin::FRAMEWORK);

        // A name qualified through an imported namespace. Framework allows it, project refuses it.
        new Cron\CronRule($name, $expression, Cron\CronRuleOrigin::FRAMEWORK);

        // A fully qualified name. Framework allows it, project refuses it.
        new \Hilos\Core\Daemon\Cron\CronRule($name, $expression, \Hilos\Core\Daemon\Cron\CronRuleOrigin::FRAMEWORK);

        // Computed origin is refused on both sides.
        new CronRule($name, $expression, $origin);

        // An unpack is not an explicit origin on either side.
        new CronRule(...$args);

        // The entry property is the backup bridge's form, and this method is not that bridge.
        new CronRule($entry->name, $entry->expression, $entry->origin);

        // BackupScheduleEntry is not judged under the framework root. Omitted origin is project.
        new BackupScheduleEntry($name, $expression, $scope, $mechanism);

        // Explicit project on an entry. Framework does not judge it, and project allows it.
        new BackupScheduleEntry($name, $expression, $scope, $mechanism, CronRuleOrigin::PROJECT);

        // Framework origin on an entry. Only a project root judges the entry.
        new BackupScheduleEntry($name, $expression, $scope, $mechanism, CronRuleOrigin::FRAMEWORK);

        // A computed fifth argument. Only a project root judges the entry.
        new BackupScheduleEntry($name, $expression, $scope, $mechanism, $origin);

        // Named framework origin on an entry. Only a project root judges it.
        new BackupScheduleEntry(
            origin: Origin::FRAMEWORK,
            name: $name,
            expression: $expression,
            scope: $scope,
            mechanism: $mechanism,
        );

        $quoted = 'new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK)';
        // new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK)
    }
}

/**
 * DaemonManager's two bridges, and a framework rule in a method that is not a bridge.
 */
class DaemonManager
{
    /**
     * @param string $name Rule name
     * @param string $expression Cron expression
     * @param mixed $origin Computed origin
     */
    protected function addCronRule(string $name, string $expression, mixed $origin): void
    {
        // The project bridge allows an omitted origin on the framework root, and so does a project root.
        new CronRule($name, $expression);

        // The project bridge allows an explicit project origin. A project root does too.
        new CronRule($name, $expression, CronRuleOrigin::PROJECT);

        // Framework origin is not the project bridge. Both policies refuse it here.
        new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK);

        // A computed origin is not the project bridge either.
        new CronRule($name, $expression, $origin);

        // A closure stays inside the method, so the bridge still refuses a framework origin.
        $hidden = function (string $name, string $expression): void {
            new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK);
        };

        // An anonymous class is not the bridge. Omitted origin is a framework hit and a project default.
        new class ($name, $expression) {
            /**
             * @param string $name Rule name
             * @param string $expression Cron expression
             */
            public function __construct(string $name, string $expression)
            {
                new CronRule($name, $expression);
            }
        };

        $quoted = 'new CronRule($name, $expression, CronRuleOrigin::FRAMEWORK)';
    }

    /**
     * @param mixed $entry Schedule entry whose origin the bridge passes through
     */
    private function registerBackupCronRules(mixed $entry): void
    {
        // The backup bridge allows $entry->origin on the framework root. A project root refuses it.
        new CronRule($entry->name, $entry->expression, $entry->origin);

        // A comment between the variable and the property is still that form.
        new CronRule($entry->name, $entry->expression, $entry /* carried */ ->origin);

        // Parentheses around the property are still that form.
        new CronRule($entry->name, $entry->expression, ($entry->origin));

        // An explicit framework case is not the bridge's form, on either root.
        new CronRule($entry->name, $entry->expression, CronRuleOrigin::FRAMEWORK);
    }

    /**
     * @param string $name Rule name
     * @param string $expression Cron expression
     */
    private function registerClusterClockCheckRule(string $name, string $expression): void
    {
        // Same class, different method: an explicit framework origin, not a bridge.
        new CronRule(
            $name,
            $expression,
            CronRuleOrigin::FRAMEWORK,
        );
    }
}

/**
 * The backup agent's bridge. The variable that is not $entry is not the bridge's form.
 */
class BackupAgent
{
    /**
     * @param mixed $entry Schedule entry whose origin the bridge passes through
     * @param mixed $schedule A different variable that only shares the property name
     */
    private function buildAgentCronRules(mixed $entry, mixed $schedule): void
    {
        // Named $entry->origin is the bridge's form. A project root still refuses a computed origin.
        new CronRule(name: $entry->name, expression: $entry->expression, origin: $entry->origin);

        // $schedule->origin is a different variable.
        new CronRule($schedule->name, $schedule->expression, $schedule->origin);

        // Nullsafe is not the written bridge.
        new CronRule($entry->name, $entry->expression, $entry?->origin);
    }
}
