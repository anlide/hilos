<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Rule\CliOptionsDeclaredRule;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use PHPUnit\Framework\TestCase;

/**
 * Runs the options rule over a toy command tree with every judged form seeded on
 * purpose and pins the exact report — both what must be caught and what must stay
 * silent.
 *
 * The guard test is green by design once every command declares what it reads, so
 * it proves nothing about whether the rule still fires. This test is the only
 * thing that does, and it fails on drift in either direction: a seeded case gone
 * unreported, or a legitimate one reported.
 *
 * The toy tree carries its own command interface rather than the production one,
 * because the fixtures follow PSR-4 like every other file under `framework/tests`
 * and a second declaration of a real interface would collide with the autoloader's.
 */
final class CliOptionsFixtureTest extends TestCase
{
    /** The toy tree, indexed and judged whole; its own command interface is inside it. */
    private const string FIXTURE_ROOT = 'CliOptionsTree';

    /** Interface of the toy hierarchy, standing in for the production command interface. */
    private const string FIXTURE_INTERFACE = 'Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree\CommandInterface';

    public function testRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'CLI-OPTIONS-DECLARED CliOptionsTree/AbstractParentCommand.php:33 — OverrideWithoutSpreadCommand reads '
                    . 'option "from-parent" and OPTIONS does not name it (see docs/agents/cli/commands.md)',
                'CLI-OPTIONS-DECLARED CliOptionsTree/HandoffCommand.php:20 — HandoffCommand passes $options whole to '
                    . 'ForeignParser::take() (see docs/agents/cli/commands.md)',
                'CLI-OPTIONS-DECLARED CliOptionsTree/OptionReader.php:19 — TraitCommand reads option "from-trait" and '
                    . 'OPTIONS does not name it (see docs/agents/cli/commands.md)',
                'CLI-OPTIONS-DECLARED CliOptionsTree/PrivateHelperCommand.php:29 — PrivateHelperCommand reads option '
                    . '"instant" and OPTIONS does not name it (see docs/agents/cli/commands.md)',
                'CLI-OPTIONS-DECLARED CliOptionsTree/UndeclaredReadCommand.php:19 — UndeclaredReadCommand reads option '
                    . '"missing" and OPTIONS does not name it (see docs/agents/cli/commands.md)',
                'CLI-OPTIONS-DECLARED CliOptionsTree/VariableKeyCommand.php:23 — VariableKeyCommand reads $options '
                    . 'with a key this guard cannot name (see docs/agents/cli/commands.md)',
            ],
            $this->report(),
            'Fixture report drifted: CLI-OPTIONS-DECLARED either stopped catching a seeded case or started reporting '
                . 'a legitimate one.',
        );
    }

    /**
     * @return array<int, string> Reported lines, in the order the rule yields them
     */
    private function report(): array
    {
        $rule = CliOptionsDeclaredRule::forInterface(self::FIXTURE_INTERFACE);
        $index = SourceIndex::forRoots(dirname(__DIR__, 2) . '/CodeStyle/Fixtures', [self::FIXTURE_ROOT]);

        $reported = [];
        foreach ($rule->check($index) as $violation) {
            $reported[] = $violation->describe($rule->doc());
        }

        return $reported;
    }
}
