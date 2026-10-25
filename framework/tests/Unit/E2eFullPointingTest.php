<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the pointing of every demo's full e2e cycle (HIL-878).
 *
 * `composer run test:e2e-full -- <spec | --grep "…">` runs the whole clean cycle — teardown, fresh
 * database, freshly booted daemon — and hands the arguments to one link only, `@test:e2e`, the
 * `npx playwright test` call. Composer forwards them nowhere else because every other link carries
 * its native ` @no_additional_args` marker (Composer 2.8.0+). A link added without the marker
 * receives the arguments too, and a spec path handed to `test:down` or `test:up` is a red first
 * link. That breaks ONLY the pointed run: the full graph in `scripts/test-suite.php` calls
 * `test:e2e-full` without arguments, the marker is cut away and the chain is what it always was —
 * so without this guard the first to find the break would be the executor editing a spec, not the
 * run that landed the link.
 *
 * The demos are found by glob rather than listed: unlike PhpunitIssueGateTest, what is checked
 * here is the chain itself, and the glob sees it in any demo that declares one. The set found has
 * to include the three demos that declare it today, so a broken path or an empty glob fails
 * instead of passing on nothing.
 */
final class E2eFullPointingTest extends TestCase
{
    /**
     * The composer script whose links are checked.
     */
    private const string CHAIN = 'test:e2e-full';

    /**
     * The full chain without the build, for a demo whose build is a graph step of its own.
     */
    private const string PREBUILT_CHAIN = 'test:e2e-prebuilt';

    /**
     * The link of the full chain that builds the frontend.
     */
    private const string BUILD_LINK = '@test:e2e-build @no_additional_args';

    /**
     * The demo that declares the prebuilt chain today; a glob that misses it is broken.
     */
    private const string PREBUILT_DEMO = 'binance-btc-tracker';

    /**
     * The one link that receives the arguments of a pointed run.
     */
    private const string POINTED_LINK = '@test:e2e';

    /**
     * The marker Composer reads as "call this link without the run's arguments".
     */
    private const string NO_ARGS_SUFFIX = ' @no_additional_args';

    /**
     * The demos that declare the chain today; a glob that misses one of them is broken.
     */
    private const array KNOWN_DEMOS = ['chat', 'tasks', 'polls', 'binance-btc-tracker', 'ecommerce-shop', 'online-testing'];

    /**
     * @throws JsonException When a demo manifest is not valid JSON
     */
    public function testEveryDemoChainHandsArgumentsToPlaywrightOnly(): void
    {
        $root = dirname(__DIR__, 3);
        $manifests = glob($root . '/demo/*/composer.json');
        $this->assertNotFalse($manifests, 'glob over demo/*/composer.json failed');

        $found = [];
        foreach ($manifests as $manifest) {
            $demo = basename(dirname($manifest));
            $chain = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['scripts'][self::CHAIN] ?? null;
            if ($chain === null) {
                continue;
            }
            $found[] = $demo;

            $this->assertIsArray($chain, "{$demo}: " . self::CHAIN . ' is not a chain of links');
            $pointed = array_filter($chain, static fn (string $link): bool => $link === self::POINTED_LINK);
            $this->assertCount(1, $pointed, "{$demo}: " . self::CHAIN . ' must carry exactly one bare ' . self::POINTED_LINK . ' link');

            foreach ($chain as $link) {
                if ($link === self::POINTED_LINK) {
                    continue;
                }
                $this->assertStringEndsWith(
                    self::NO_ARGS_SUFFIX,
                    $link,
                    "{$demo}: link '{$link}' of " . self::CHAIN . ' would receive the arguments of a pointed run',
                );
            }
        }

        foreach (self::KNOWN_DEMOS as $demo) {
            $this->assertContains($demo, $found, "demo '{$demo}' declares " . self::CHAIN . ' but the glob did not find it');
        }
    }

    /**
     * A demo whose build is a graph step of its own runs `test:e2e-prebuilt` there instead of
     * the full chain (scripts/test-suite.php). The two chains are written out twice, so this
     * holds them together: the prebuilt one is the full one without its build link, and thereby
     * keeps its pointing too. A link added to one chain alone fails here, not in a full run.
     *
     * @throws JsonException When a demo manifest is not valid JSON
     */
    public function testEveryPrebuiltChainIsTheFullChainWithoutItsBuild(): void
    {
        $root = dirname(__DIR__, 3);
        $manifests = glob($root . '/demo/*/composer.json');
        $this->assertNotFalse($manifests, 'glob over demo/*/composer.json failed');

        $found = [];
        foreach ($manifests as $manifest) {
            $demo = basename(dirname($manifest));
            $decoded = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            if (!isset($decoded['scripts'][self::PREBUILT_CHAIN])) {
                continue;
            }
            $scripts = $decoded['scripts'];
            $found[] = $demo;

            $this->assertContains(self::BUILD_LINK, $scripts[self::CHAIN], "{$demo}: " . self::CHAIN . ' carries no build link');
            $this->assertSame(
                array_values(array_diff($scripts[self::CHAIN], [self::BUILD_LINK])),
                $scripts[self::PREBUILT_CHAIN],
                "{$demo}: " . self::PREBUILT_CHAIN . ' is not ' . self::CHAIN . ' without ' . self::BUILD_LINK,
            );
        }

        $this->assertContains(
            self::PREBUILT_DEMO,
            $found,
            self::PREBUILT_DEMO . ' declares ' . self::PREBUILT_CHAIN . ' but the glob did not find it',
        );
    }
}
