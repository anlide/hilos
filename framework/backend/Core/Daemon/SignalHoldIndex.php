<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Generator;

/** One local obligation index; finite readers keep no array snapshot or payload reference. */
final class SignalHoldIndex
{
    private int $watermark = 0;

    private ?SignalHoldIndexNode $root = null;

    /** @param int $id Stable order identity; later additions normally have larger identities */
    public function add(int $id): void
    {
        $this->root = self::insert($this->root, $id);
        $this->watermark = max($this->watermark, $id);
    }

    /** @param int $id Settled identity */
    public function remove(int $id): void
    {
        $this->root = self::removeNode($this->root, $id);
    }

    /** @return bool Whether this index has no outstanding member */
    public function isEmpty(): bool
    {
        return $this->root === null;
    }

    /** @return ?int Last still-pending admission, absent when this exact cohort is empty */
    public function lastIdentity(): ?int
    {
        $node = $this->root;
        if ($node === null) {
            return null;
        }
        while ($node->right !== null) {
            $node = $node->right;
        }
        return $node->identity;
    }

    /**
     * @param ?int $watermark Last allowed predecessor, absent for the current finite cohort
     * @return Generator<int, int> Original live identities, without later additions
     */
    public function candidates(?int $watermark = null): Generator
    {
        return $this->originalCandidates($this->root === null ? null
            : ($watermark === null ? $this->watermark : min($watermark, $this->watermark)));
    }

    /**
     * @param ?int $after Previously visited identity, absent before the first visit
     * @return ?int First current identity strictly after the cursor, absent at the live end
     */
    public function successor(?int $after): ?int
    {
        $node = $this->root;
        $identity = null;
        while ($node !== null) {
            if ($after === null || $node->identity > $after) {
                $identity = $node->identity;
                $node = $node->left;
            } else {
                $node = $node->right;
            }
        }
        return $identity;
    }

    /**
     * Each successor search follows O(log live identities) scalar branches. Deleted hash slots
     * cannot hide an unbounded walk inside next(), and canceled cursors retain no tree snapshot.
     *
     * @param ?int $watermark Original finite tail, absent for an empty captured index
     * @return Generator<int, int> Still-present original identities
     */
    private function originalCandidates(?int $watermark): Generator
    {
        if ($watermark === null) {
            return;
        }
        $after = null;
        while (($id = $this->successor($after)) !== null && $id <= $watermark) {
            $after = $id;
            yield $id;
        }
    }

    /**
     * @param ?SignalHoldIndexNode $node Current scalar subtree
     * @param int $identity Stable order identity
     * @return SignalHoldIndexNode Balanced subtree containing the identity once
     */
    private static function insert(?SignalHoldIndexNode $node, int $identity): SignalHoldIndexNode
    {
        if ($node === null) {
            return new SignalHoldIndexNode($identity);
        }
        if ($identity < $node->identity) {
            $node->left = self::insert($node->left, $identity);
        } elseif ($identity > $node->identity) {
            $node->right = self::insert($node->right, $identity);
        } else {
            return $node;
        }
        return self::balance($node);
    }

    /**
     * @param ?SignalHoldIndexNode $node Current scalar subtree
     * @param int $identity Identity being retired
     * @return ?SignalHoldIndexNode Balanced remainder, absent when the subtree becomes empty
     */
    private static function removeNode(?SignalHoldIndexNode $node, int $identity): ?SignalHoldIndexNode
    {
        if ($node === null) {
            return null;
        }
        if ($identity < $node->identity) {
            $node->left = self::removeNode($node->left, $identity);
        } elseif ($identity > $node->identity) {
            $node->right = self::removeNode($node->right, $identity);
        } else {
            if ($node->left === null) {
                return $node->right;
            }
            if ($node->right === null) {
                return $node->left;
            }
            $successor = $node->right;
            while ($successor->left !== null) {
                $successor = $successor->left;
            }
            $node->identity = $successor->identity;
            $node->right = self::removeNode($node->right, $successor->identity);
        }
        return self::balance($node);
    }

    /**
     * @param SignalHoldIndexNode $node Scalar subtree changed by one insertion or removal
     * @return SignalHoldIndexNode Root after restoring the AVL height invariant
     */
    private static function balance(SignalHoldIndexNode $node): SignalHoldIndexNode
    {
        $node->height = max(self::height($node->left), self::height($node->right)) + 1;
        if ($node->left !== null && self::height($node->left) - self::height($node->right) > 1) {
            if (self::height($node->left->left) < self::height($node->left->right)) {
                $node->left = self::rotateLeft($node->left);
            }
            return self::rotateRight($node);
        }
        if ($node->right !== null && self::height($node->right) - self::height($node->left) > 1) {
            if (self::height($node->right->right) < self::height($node->right->left)) {
                $node->right = self::rotateRight($node->right);
            }
            return self::rotateLeft($node);
        }
        return $node;
    }

    /**
     * @param SignalHoldIndexNode $node Subtree with a right child, established by balance()
     * @return SignalHoldIndexNode Rotated root with both heights updated
     */
    private static function rotateLeft(SignalHoldIndexNode $node): SignalHoldIndexNode
    {
        $pivot = $node->right;
        $node->right = $pivot->left;
        $pivot->left = $node;
        $node->height = max(self::height($node->left), self::height($node->right)) + 1;
        $pivot->height = max(self::height($pivot->left), self::height($pivot->right)) + 1;
        return $pivot;
    }

    /**
     * @param SignalHoldIndexNode $node Subtree with a left child, established by balance()
     * @return SignalHoldIndexNode Rotated root with both heights updated
     */
    private static function rotateRight(SignalHoldIndexNode $node): SignalHoldIndexNode
    {
        $pivot = $node->left;
        $node->left = $pivot->right;
        $pivot->right = $node;
        $node->height = max(self::height($node->left), self::height($node->right)) + 1;
        $pivot->height = max(self::height($pivot->left), self::height($pivot->right)) + 1;
        return $pivot;
    }

    /**
     * @param ?SignalHoldIndexNode $node Current scalar child
     * @return int Height, zero for an absent child
     */
    private static function height(?SignalHoldIndexNode $node): int
    {
        return $node?->height ?? 0;
    }
}
