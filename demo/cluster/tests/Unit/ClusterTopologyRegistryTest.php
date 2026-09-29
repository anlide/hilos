<?php

declare(strict_types=1);

namespace Demo\Cluster\Tests\Unit;

use Demo\Cluster\Core\Router\ClusterSignalRouter;
use Demo\Cluster\Database\ClusterDbContext;
use Demo\Cluster\Hilos;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\CLI\CliManager;
use Hilos\HilosException;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the cluster demo's project topology registry and its headless,
 * transport-only surface.
 */
final class ClusterTopologyRegistryTest extends TestCase
{
    public function testProjectIsHeadlessWithNoPagesOrGroups(): void
    {
        // The demo carries no browser surface at all: no pages, groups, or tables.
        $this->assertSame([], Hilos::PAGES);
        $this->assertSame([], Hilos::GROUPS);
        $this->assertSame([], Hilos::TABLES);
        $this->assertSame([], Hilos::getPageRoutes());
        $this->assertSame([], Hilos::getPageAgentIndexRoutes());
    }

    public function testRoutingSurfaceIsEmptyApartFromTheProbeCommands(): void
    {
        // Nothing routes: no page/agent actions, no server-driven signals.
        $this->assertSame([], Hilos::getPageActionRoutes());
        $this->assertSame([], Hilos::getActionAgentRoutes());
        $this->assertSame([], Hilos::getPageSignalAgentRoutes());
        $this->assertSame([], Hilos::getAgentSignalRoutes());
        $this->assertSame([], Hilos::getGroupRoutes());

        // The exceptions are commands, and the framework's probes carry them: this demo is
        // headless, so an agent is the only thing that can carry work a scenario drives. The
        // database probe carries the database pair, which the master must not answer because a
        // database read blocks; the set probe carries the runtime write, which has to pass the
        // truth-source door of the node that owns a set. The probe fleet carries no command: the
        // protected-mode drive is the index agent's, and this demo has none.
        $this->assertSame([
            CliCommands::CLUSTER_TEST_DB_WRITE => HilosAgentType::HILOS_PROBE_DB,
            CliCommands::CLUSTER_TEST_DB_READ => HilosAgentType::HILOS_PROBE_DB,
            CliCommands::CLUSTER_TEST_RT_WRITE => HilosAgentType::HILOS_PROBE_RT_SET,
        ], Hilos::getCommandAgentRoutes());
    }

    public function testTheRegistryListsTheFiveFrameworkProbesAsTheFrameworkWroteThem(): void
    {
        $this->assertSame(
            [
                HilosAgentType::HILOS_PROBE_FLEET,
                HilosAgentType::HILOS_PROBE_CLAIMER,
                HilosAgentType::HILOS_PROBE_BALLAST,
                HilosAgentType::HILOS_PROBE_DB,
                HilosAgentType::HILOS_PROBE_RT_SET,
            ],
            array_keys(Hilos::AGENTS),
        );

        // The rows are the framework's records, not the demo's copy of them: the flags every
        // scenario stands on are pinned once, in the framework's own registry test.
        foreach (Hilos::AGENTS as $agentType => $entry) {
            $this->assertSame(ClusterProbe::AGENTS[$agentType], $entry, "{$agentType} is listed as the framework wrote it");
        }
    }

    public function testNoAgentIsStartedOnTheBootstrapSignal(): void
    {
        // Nothing this demo registers is booted from the signal: the fleet, the claimer and the
        // ballast are leader-placed over the peer channel, and the node probes come up with their
        // node's own workers, so the bootstrap list stays empty.
        $method = new ReflectionMethod(ClusterSignalRouter::class, 'getDefaultSystemBootstrapAgentTypes');
        $bootstrapAgents = $method->invoke(new ClusterSignalRouter());

        $this->assertSame([], $bootstrapAgents);
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    /**
     * The length of the shared-ownership debt, so no receipt can be written in silence.
     *
     * A ceiling and not the exact rows: an addition paints this red, a removal passes quietly,
     * because nothing should stand in the way of a debt getting smaller. Asserting the exact
     * contents instead would paint this test red on every PARTING - the one move the list
     * exists to bring about. No database collection is shared here; one runtime collection is, and
     * that one on purpose.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $this->assertSame([], Hilos::SHARED_DB_OWNERS);
        $this->assertLessThanOrEqual(1, count(Hilos::SHARED_RT_OWNERS));
    }

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The deferred half of the same check. It stays here on a project that declares no
        // feature precisely because that is a state worth guarding: the demo carries a historical
        // hilos_settings migration, and the day someone declares SETTINGS over it, this test is
        // what asks for the rest. Without a single feature it is still asked for the verifier
        // circle table: the demo builds a runtime context, so it can freeze, and the circle
        // belongs to the freeze (HIL-1118).
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            ClusterRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @throws HilosException When the context refuses to configure, or the guard refuses a chain under a framework key
     */
    public function testFrameworkExtensionsAreWhole(): void
    {
        // The question the daemon asks first on its start, over this project's context and
        // without a database: configure() reads nothing. No framework key is extended here
        // today, so every key passes as the framework's own; the day one is, this names the
        // refusal in seconds instead of a node that did not come up.
        $previous = Hilos::$db;
        try {
            Hilos::$db = new ClusterDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
        } finally {
            Hilos::$db = $previous;
        }

        $this->addToAssertionCount(1);
    }
}
