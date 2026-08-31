<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\Throws\ClassRecord;
use Hilos\Tests\CodeStyle\Throws\CrossFileRule;
use Hilos\Tests\CodeStyle\Throws\SourceIndex;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces the reach declaration of subscriptions.md: every page says out loud
 * whether the browser navigates to it, and a page that says it does not may not lean
 * on READS_DB.
 *
 * The defect behind it is invisible in one file. Interest in a DB collection is taken
 * up on a page subscription and let go on unsubscribe, while an action is routed by a
 * global action-to-page map and its frame carries no page name — so an action of a
 * page nobody subscribed to in this worker runs where its READS_DB was never taken up
 * and is refused, at the moment the user pressed the button. Nothing in the page's own
 * file says which of the two kinds it is, which is why the answer is declared rather
 * than guessed and why the check is cross-file: the answer is usually inherited.
 *
 * Only concrete classes are judged. An abstract base is a legal place to declare and is
 * never itself required to: PAGES registers concrete classes, and only a concrete page
 * can host an action.
 */
final class PageReachRule implements CrossFileRule
{
    public const string ID = 'PAGE-REACH';

    private const string DOC = 'docs/agents/signals/subscriptions.md';

    /** The base every page class reaches, and the only thing that makes a class a page here. */
    private const string PAGE_BASE = 'Hilos\Core\Page\AbstractPage';

    /** Constant the reach is declared through. */
    private const string REACH = 'REACH';

    /** Constant naming the collections a page reads beyond what its tables name. */
    private const string READS_DB = 'READS_DB';

    /** The one case a page may not keep, spelled as it stands in the source. */
    private const string UNDECLARED = 'PageReach::UNDECLARED';

    /** The case that says nobody navigates here. */
    private const string ACTION_HOST = 'PageReach::ACTION_HOST';

    /** Value text of a list that names nothing. */
    private const string EMPTY_LIST = '[]';

    /**
     * The two common roots of the page hierarchy, which may carry nothing but
     * UNDECLARED: a real answer on either would declare every page in the repository
     * at once and leave the rule with nothing to find. They are named here, with the
     * reason, rather than recorded in the baseline — the shape RT-STATE-MUTATE and
     * DB-OBJECT-MUTATE already use for a legal exception, because a baseline record
     * reads as owed work and this is a permanent property of a root.
     *
     * @var array<int, string>
     */
    private const array COMMON_ROOTS = [self::PAGE_BASE, 'Hilos\Core\Page\AbstractHilosPage'];

    private string $pageBase;

    /** @var array<int, string> Roots of the judged hierarchy that may carry nothing but UNDECLARED */
    private array $commonRoots;

    /**
     * @param string $pageBase Fully qualified base every judged page reaches
     * @param array<int, string> $commonRoots Roots of that hierarchy which may carry nothing but UNDECLARED
     */
    private function __construct(string $pageBase, array $commonRoots)
    {
        $this->pageBase = $pageBase;
        $this->commonRoots = $commonRoots;
    }

    /**
     * @return self Rule that judges the page hierarchy of the repository
     */
    public static function forPageHierarchy(): self
    {
        return new self(self::PAGE_BASE, self::COMMON_ROOTS);
    }

    /**
     * The hierarchy is named from outside for the fixtures alone. A toy tree cannot
     * declare the production names — the fixture roots follow PSR-4 like every other
     * file under `framework/tests`, and a second declaration of a real class name
     * would collide with the one the autoloader already answers with.
     *
     * @param string $pageBase Fully qualified base every judged page reaches
     * @param array<int, string> $commonRoots Roots of that hierarchy which may carry nothing but UNDECLARED
     * @return self Rule that judges the named hierarchy by the very same code
     */
    public static function forHierarchy(string $pageBase, array $commonRoots): self
    {
        return new self($pageBase, $commonRoots);
    }

    /**
     * @return string Rule id
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string Owning document
     */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param SourceIndex $index Indexed source tree of every production root
     * @return iterable<Violation> One entry per page class, ordered by file and line
     */
    public function check(SourceIndex $index): iterable
    {
        $violations = [];
        foreach ($index->classes() as $class) {
            $violations = [...$violations, ...$this->judge($index, $class)];
        }

        usort($violations, static fn(Violation $left, Violation $right): int
            => [$left->relativePath, $left->line] <=> [$right->relativePath, $right->line]);

        yield from $violations;
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $class Class to judge
     * @return array<int, Violation> Hits on this class, empty when it is not a page or has nothing to answer for
     */
    private function judge(SourceIndex $index, ClassRecord $class): array
    {
        if (!$this->isPage($index, $class)) {
            return [];
        }

        if (in_array($class->name, $this->commonRoots, true)) {
            return $this->judgeRoot($class);
        }

        if ($class->isAbstract) {
            return [];
        }

        $reach = $index->resolveConstant($class->name, self::REACH);
        if ($reach === null || $reach === self::UNDECLARED) {
            return [new Violation(
                self::ID,
                $class->path,
                $class->line,
                $class->shortName() . ' declares no PageReach: say whether the browser navigates here (ROUTE)'
                    . ' or the page only hosts actions arriving while the person is on another page (ACTION_HOST)',
            )];
        }

        return $this->judgeActionHost($index, $class, $reach);
    }

    /**
     * A root of the hierarchy answers for nobody but itself, so the only thing it can
     * get wrong is carrying an answer at all.
     *
     * @param ClassRecord $class One of the common roots
     * @return array<int, Violation> The hit, or nothing when the root carries no answer
     */
    private function judgeRoot(ClassRecord $class): array
    {
        $declared = $class->constants[self::REACH] ?? self::UNDECLARED;
        if ($declared === self::UNDECLARED) {
            return [];
        }

        return [new Violation(
            self::ID,
            $class->path,
            $class->line,
            $class->shortName() . ' is a common root of the page hierarchy and may declare no PageReach but'
                . ' UNDECLARED: an answer here declares every page in the repository and leaves nothing to check',
        )];
    }

    /**
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $class Page whose reach resolved to something
     * @param string $reach Raw value text the reach resolved to
     * @return array<int, Violation> The hit, or nothing when the page is navigable or reads nothing
     */
    private function judgeActionHost(SourceIndex $index, ClassRecord $class, string $reach): array
    {
        if ($reach !== self::ACTION_HOST) {
            return [];
        }

        $reads = $index->resolveConstant($class->name, self::READS_DB);
        if ($reads === null || $reads === self::EMPTY_LIST) {
            return [];
        }

        return [new Violation(
            self::ID,
            $class->path,
            $class->line,
            $class->shortName() . ' is an ACTION_HOST and still fills READS_DB: that list is only taken up on a'
                . ' page subscription, so these reads belong in DbContext::processWideReadCollections()',
        )];
    }

    /**
     * A class is a page when its parent chain reaches the page base — the same link the
     * declaration itself is resolved through, so a page cannot be a page for the guard
     * and inherit from somewhere else for the declaration.
     *
     * @param SourceIndex $index Indexed source tree
     * @param ClassRecord $class Class to place
     * @return bool True when the chain reaches the page base, the base itself included
     */
    private function isPage(SourceIndex $index, ClassRecord $class): bool
    {
        $seen = [];
        $current = $class;
        while (!isset($seen[strtolower($current->name)])) {
            if ($current->name === $this->pageBase) {
                return true;
            }
            $seen[strtolower($current->name)] = true;
            if ($current->parent === null) {
                return false;
            }
            $parent = $index->find($current->parent);
            if ($parent === null) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }
}
