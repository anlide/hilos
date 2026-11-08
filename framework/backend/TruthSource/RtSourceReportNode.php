<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

/** Immutable ordered report tree: a changed path is copied, never a retained reader's whole map. */
final readonly class RtSourceReportNode
{
    public int $height;

    /**
     * @param int $position Original agent insertion position
     * @param RtSourceReport $report Immutable report values
     * @param ?self $left Earlier agent positions
     * @param ?self $right Later agent positions
     */
    public function __construct(
        public int $position,
        public RtSourceReport $report,
        public ?self $left = null,
        public ?self $right = null,
    ) {
        $this->height = max($left?->height ?? 0, $right?->height ?? 0) + 1;
    }

    /**
     * @param ?self $node Original immutable subtree
     * @param int $position Stable agent insertion position
     * @param RtSourceReport $report Replacement values
     * @return self New root sharing every unchanged branch
     */
    public static function put(?self $node, int $position, RtSourceReport $report): self
    {
        if ($node === null) {
            return new self($position, $report);
        }
        if ($position === $node->position) {
            return new self($position, $report, $node->left, $node->right);
        }
        return self::balance($position < $node->position
            ? new self($node->position, $node->report, self::put($node->left, $position, $report), $node->right)
            : new self($node->position, $node->report, $node->left, self::put($node->right, $position, $report)));
    }

    /**
     * @param ?self $node Original immutable subtree
     * @param int $position Departed agent position
     * @return ?self New root without that position, sharing unchanged branches
     */
    public static function remove(?self $node, int $position): ?self
    {
        if ($node === null) {
            return null;
        }
        if ($position < $node->position) {
            return self::balance(new self($node->position, $node->report, self::remove($node->left, $position), $node->right));
        }
        if ($position > $node->position) {
            return self::balance(new self($node->position, $node->report, $node->left, self::remove($node->right, $position)));
        }
        if ($node->left === null) {
            return $node->right;
        }
        if ($node->right === null) {
            return $node->left;
        }
        $next = $node->right;
        while ($next->left !== null) {
            $next = $next->left;
        }
        return self::balance(new self($next->position, $next->report, $node->left, self::remove($node->right, $next->position)));
    }

    /**
     * @param self $node Newly copied path node
     * @return self Balanced root; every surviving original node stays immutable
     */
    private static function balance(self $node): self
    {
        if ($node->left !== null && $node->left->height - ($node->right?->height ?? 0) > 1) {
            $left = $node->left;
            if (($left->left?->height ?? 0) < ($left->right?->height ?? 0)) {
                $left = self::rotateLeft($left);
            }
            return self::rotateRight(new self($node->position, $node->report, $left, $node->right));
        }
        if ($node->right !== null && $node->right->height - ($node->left?->height ?? 0) > 1) {
            $right = $node->right;
            if (($right->right?->height ?? 0) < ($right->left?->height ?? 0)) {
                $right = self::rotateRight($right);
            }
            return self::rotateLeft(new self($node->position, $node->report, $node->left, $right));
        }
        return $node;
    }

    /**
     * @param self $node Right-heavy subtree with a right child, established by balance()
     * @return self Rotated immutable root
     */
    private static function rotateLeft(self $node): self
    {
        $right = $node->right;
        return new self($right->position, $right->report,
            new self($node->position, $node->report, $node->left, $right->left), $right->right);
    }

    /**
     * @param self $node Left-heavy subtree with a left child, established by balance()
     * @return self Rotated immutable root
     */
    private static function rotateRight(self $node): self
    {
        $left = $node->left;
        return new self($left->position, $left->report, $left->left,
            new self($node->position, $node->report, $left->right, $node->right));
    }
}
