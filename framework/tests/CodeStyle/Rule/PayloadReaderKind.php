<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

/**
 * What a judged reader is handed, and therefore what an absent key means in it.
 *
 * The two kinds are not two rules: the same three literals are minted by the same
 * three spellings in both, and the report differs only in the cure it names. What
 * differs is the meaning of the missing key. In a full row or frame the key is
 * owed, so its absence says the payload is broken. In a diff only the changed
 * fields travel, so an absent key says the field did not change — and a reader
 * that mints a stub there overwrites a value nobody touched.
 *
 * The kind is declared by the reader's NAME, in {@see PayloadSentinelRule}, and is
 * the whole answer to which cure a report carries. The rule is a token walk and
 * reads no class hierarchy: the families of `fromRow()` and `applyDiff()` are
 * declared `final` and only delegate, so the reading itself lives in the helpers
 * the name list also carries.
 */
enum PayloadReaderKind
{
    /** A whole row or frame, where an absent required key means it is broken. */
    case FullRow;

    /** A partial update, where an absent key means that field did not change. */
    case Diff;
}
