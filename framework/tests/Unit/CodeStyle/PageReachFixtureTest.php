<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Rule\PageReachRule;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use PHPUnit\Framework\TestCase;

/**
 * Runs the reach rule over a toy hierarchy with every judged form seeded on purpose
 * and pins the exact report — both what must be caught and what must stay silent.
 *
 * The guard test is green by design once the repository is marked, so it proves
 * nothing about whether the rule still fires. This test is the only thing that does,
 * and it fails on drift in either direction: a seeded case gone unreported, or a
 * legitimate one reported.
 *
 * The toy tree carries its own base rather than the production one, because the
 * fixtures follow PSR-4 like every other file under `framework/tests` and a second
 * declaration of a real class name would collide with the autoloader's.
 */
final class PageReachFixtureTest extends TestCase
{
    /** The toy tree, indexed and judged whole; its own page base is inside it. */
    private const string FIXTURE_ROOT = 'PageReachTree';

    /** Base of the toy hierarchy, standing in for Hilos\Core\Page\AbstractPage. */
    private const string FIXTURE_BASE = 'Hilos\Tests\CodeStyle\Fixtures\PageReachTree\AbstractPage';

    /**
     * Roots of the toy hierarchy. The third one is a root that answers anyway, which
     * is the only way to seed the finding roots earn.
     *
     * @var array<int, string>
     */
    private const array FIXTURE_ROOTS = [
        self::FIXTURE_BASE,
        'Hilos\Tests\CodeStyle\Fixtures\PageReachTree\AbstractHilosPage',
        'Hilos\Tests\CodeStyle\Fixtures\PageReachTree\AbstractLoudRoot',
    ];

    public function testRuleReportsExactlyTheSeededCases(): void
    {
        $this->assertSame(
            [
                'PAGE-REACH PageReachTree/AbstractLoudRoot.php:10 — AbstractLoudRoot is a common root of the page '
                    . 'hierarchy and may declare no PageReach but UNDECLARED: an answer here declares every page in '
                    . 'the repository and leaves nothing to check (see docs/agents/signals/subscriptions.md)',
                'PAGE-REACH PageReachTree/ActionHostPage.php:10 — ActionHostPage is an ACTION_HOST and still fills '
                    . 'READS_DB: that list is only taken up on a page subscription, so these reads belong in '
                    . 'DbContext::processWideReadCollections() (see docs/agents/signals/subscriptions.md)',
                'PAGE-REACH PageReachTree/InheritedReadsPage.php:11 — InheritedReadsPage is an ACTION_HOST and still '
                    . 'fills READS_DB: that list is only taken up on a page subscription, so these reads belong in '
                    . 'DbContext::processWideReadCollections() (see docs/agents/signals/subscriptions.md)',
                'PAGE-REACH PageReachTree/MissingPage.php:10 — MissingPage declares no PageReach: say whether the '
                    . 'browser navigates here (ROUTE) or the page only hosts actions arriving while the person is on '
                    . 'another page (ACTION_HOST) (see docs/agents/signals/subscriptions.md)',
            ],
            $this->report(),
            'Fixture report drifted: PAGE-REACH either stopped catching a seeded case or started reporting a '
                . 'legitimate one.',
        );
    }

    /**
     * @return array<int, string> Reported lines, in the order the rule yields them
     */
    private function report(): array
    {
        $rule = PageReachRule::forHierarchy(self::FIXTURE_BASE, self::FIXTURE_ROOTS);
        $index = SourceIndex::forRoots(dirname(__DIR__, 2) . '/CodeStyle/Fixtures', [self::FIXTURE_ROOT]);

        $reported = [];
        foreach ($rule->check($index) as $violation) {
            $reported[] = $violation->describe($rule->doc());
        }

        return $reported;
    }
}
