<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\Snapshot\RtWork;

/** Structural equality over immutable values, including nested maps and long text. */
final class RtRowComparison
{
    /**
     * @param RtRowReader $left Current value
     * @param RtRowReader $right Baseline value
     * @return Generator<int, RtWork, int, bool> Strict native-value equality without materialization
     * @throws InvalidFormatException When stored pages have an invalid shape
     */
    public static function equal(RtRowReader $left, RtRowReader $right): Generator
    {
        $stack = new RtRowCompareStack($left->arena());
        yield new RtWork();
        $stack->push($left, $right);
        while ($stack->head !== null) {
            yield new RtWork();
            $frame = $stack->head;
            if ($frame->left->kind() !== $frame->right->kind()) {
                return false;
            }
            if ($frame->left->kind() === RtRowKind::Scalar) {
                if ((yield from $frame->left->scalar()) !== (yield from $frame->right->scalar())) {
                    return false;
                }
            } elseif ($frame->left->kind() === RtRowKind::Text) {
                if (!(yield from self::equalText($frame->left, $frame->right))) {
                    return false;
                }
            } else {
                if ($frame->leftCursor === null) {
                    $frame->leftCursor = $frame->left->entries();
                    $frame->rightCursor = $frame->right->entries();
                }
                if ($frame->phase === RtRowCompareFrame::COMPARE_VALUE) {
                    $frame->phase = RtRowCompareFrame::NEXT_ENTRY;
                    $stack->push($frame->leftEntry->value->reader(), $frame->rightEntry->value->reader());
                    continue;
                }
                $frame->leftEntry = yield from $frame->leftCursor->advance();
                $frame->rightEntry = yield from $frame->rightCursor->advance();
                if ($frame->leftEntry === null || $frame->rightEntry === null) {
                    if ($frame->leftEntry !== $frame->rightEntry) {
                        return false;
                    }
                } else {
                    $frame->phase = RtRowCompareFrame::COMPARE_VALUE;
                    $stack->push($frame->leftEntry->key->reader(), $frame->rightEntry->key->reader());
                    continue;
                }
            }
            $stack->pop();
        }

        return true;
    }

    /**
     * @param RtRowReader $left Current text
     * @param RtRowReader $right Baseline text
     * @return Generator<int, RtWork, int, bool> Equality independent of page and budget boundaries
     * @throws InvalidFormatException When a stored value is not text
     */
    private static function equalText(RtRowReader $left, RtRowReader $right): Generator
    {
        $a = $left->text();
        $b = $right->text();
        $leftPart = '';
        $rightPart = '';
        $leftOffset = 0;
        $rightOffset = 0;
        while (true) {
            if ($leftOffset === strlen($leftPart)) {
                $leftPart = yield from $a->advance();
                $leftOffset = 0;
            }
            if ($rightOffset === strlen($rightPart)) {
                $rightPart = yield from $b->advance();
                $rightOffset = 0;
            }
            if ($leftPart === null || $rightPart === null) {
                return $leftPart === $rightPart;
            }
            $length = yield new RtWork(min(strlen($leftPart) - $leftOffset, strlen($rightPart) - $rightOffset));
            if (substr($leftPart, $leftOffset, $length) !== substr($rightPart, $rightOffset, $length)) {
                return false;
            }
            $leftOffset += $length;
            $rightOffset += $length;
        }
    }
}
