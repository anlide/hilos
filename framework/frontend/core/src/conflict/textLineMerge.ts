import type { RowEditMergeResult } from './rowEdit.js'

/** A complete line merge, or a refusal with no partially merged text. */
export type TextLineMergeResult = RowEditMergeResult<string>

interface LineMatch {
  readonly baseline: number
  readonly changed: number
}

interface LineChange {
  readonly start: number
  readonly end: number
  readonly lines: readonly string[]
  readonly deletionOnly: boolean
}

/** Keep each LF or CRLF on its line, including the last line's terminator. */
function linesOf(text: string): string[] {
  return text.match(/[^\n]*\n|[^\n]+$/g) ?? []
}

/** One LCS score row; reversing both ranges gives scores from the other end. */
function matchLengths(
  baseline: readonly string[],
  start: number,
  end: number,
  changed: readonly string[],
  changedStart: number,
  changedEnd: number,
  reverse: boolean,
): Uint32Array {
  const width = changedEnd - changedStart
  const lengths = new Uint32Array(width + 1)
  for (let row = 0; row < end - start; row++) {
    const line = baseline[reverse ? end - row - 1 : start + row]
    let diagonal = 0
    for (let column = 1; column <= width; column++) {
      const above = lengths[column]
      lengths[column] =
        line ===
        changed[reverse ? changedEnd - column : changedStart + column - 1]
          ? diagonal + 1
          : Math.max(above, lengths[column - 1])
      diagonal = above
    }
  }
  return lengths
}

/** Release the score rows before descending into either half of Hirschberg. */
function splitMatch(
  baseline: readonly string[],
  start: number,
  middle: number,
  end: number,
  changed: readonly string[],
  changedStart: number,
  changedEnd: number,
  later: boolean,
): number {
  const before = matchLengths(
    baseline,
    start,
    middle,
    changed,
    changedStart,
    changedEnd,
    false,
  )
  const after = matchLengths(
    baseline,
    middle,
    end,
    changed,
    changedStart,
    changedEnd,
    true,
  )
  const width = changedEnd - changedStart
  let best = -1
  let split = 0
  for (let column = 0; column <= width; column++) {
    const score = before[column] + after[width - column]
    if (score > best || (later && score === best)) {
      best = score
      split = column
    }
  }
  return changedStart + split
}

/** The two tie directions trace the outermost optimal line alignments. */
function lineMatches(
  baseline: readonly string[],
  changed: readonly string[],
  later: boolean,
): LineMatch[] {
  const matches: LineMatch[] = []
  function visit(
    start: number,
    end: number,
    changedStart: number,
    changedEnd: number,
  ): void {
    if (start === end || changedStart === changedEnd) return
    if (end - start === 1) {
      for (let offset = 0; offset < changedEnd - changedStart; offset++) {
        const column = later ? changedEnd - offset - 1 : changedStart + offset
        if (baseline[start] === changed[column]) {
          matches.push({ baseline: start, changed: column })
          return
        }
      }
      return
    }
    if (
      end - start === changedEnd - changedStart &&
      baseline
        .slice(start, end)
        .every((line, index) => line === changed[changedStart + index])
    ) {
      for (let index = 0; index < end - start; index++) {
        matches.push({ baseline: start + index, changed: changedStart + index })
      }
      return
    }
    const middle = start + Math.floor((end - start) / 2)
    const split = splitMatch(
      baseline,
      start,
      middle,
      end,
      changed,
      changedStart,
      changedEnd,
      later,
    )
    visit(start, middle, changedStart, split)
    visit(middle, end, split, changedEnd)
  }
  visit(0, baseline.length, 0, changed.length)
  return matches
}

/**
 * Only matches shared by both outer alignments can separate changes safely.
 * A repeated line with several possible partners stays inside one atomic
 * range, so an overlapping edit cannot depend on an arbitrary diff tie.
 * Score rows and matches take linear memory; no N by M matrix is retained.
 */
function lineChanges(
  baseline: readonly string[],
  changed: readonly string[],
): LineChange[] {
  const earlier = lineMatches(baseline, changed, false)
  const later = lineMatches(baseline, changed, true)
  const changes: LineChange[] = []
  let left = 0
  let right = 0
  let start = 0
  let changedStart = 0
  let matchStart = 0

  function append(
    end: number,
    changedEnd: number,
    matchEnd: number,
    ambiguous: boolean,
  ): void {
    if (end === start && changedEnd === changedStart) return
    if (!ambiguous && end - start === changedEnd - changedStart) {
      // With no internal match to place differently, equally sized replacements
      // are edits of individual lines. Shared edits then deduplicate even beside
      // another changed line on only one side.
      for (let index = start; index < end; index++) {
        changes.push({
          start: index,
          end: index + 1,
          lines: [changed[changedStart + index - start]],
          deletionOnly: false,
        })
      }
    } else {
      changes.push({
        start,
        end,
        lines: changed.slice(changedStart, changedEnd),
        // Matching every output line proves this range only deletes, even if
        // ambiguity keeps retained repeats inside the atomic range.
        deletionOnly: matchEnd - matchStart === changedEnd - changedStart,
      })
    }
  }

  let ambiguous = false
  while (left < earlier.length && right < later.length) {
    const first = earlier[left]
    const second = later[right]
    if (
      first.baseline === second.baseline &&
      first.changed === second.changed
    ) {
      append(first.baseline, first.changed, left, ambiguous)
      start = first.baseline + 1
      changedStart = first.changed + 1
      ambiguous = false
      left++
      right++
      matchStart = left
    } else {
      ambiguous = true
      if (first.baseline <= second.baseline) left++
      if (second.baseline <= first.baseline) right++
    }
  }
  append(
    baseline.length,
    changed.length,
    earlier.length,
    ambiguous || left < earlier.length || right < later.length,
  )
  return changes
}

/** Two insertions meet at a point; a boundary insertion stays beside a range. */
function overlaps(first: LineChange, second: LineChange): boolean {
  if (first.start === first.end && second.start === second.end) {
    return first.start === second.start
  }
  if (first.start === first.end) {
    return insertionOverlaps(first.start, second)
  }
  if (second.start === second.end) {
    return insertionOverlaps(second.start, first)
  }
  return first.start < second.end && second.start < first.end
}

function insertionOverlaps(position: number, change: LineChange): boolean {
  if (change.start < position && position < change.end) return true
  // An atomic mixed replacement may itself insert at either edge. Without an
  // unchanged line separating them, an extra insertion there could compete or
  // duplicate it. Pure deletion edges and individual replacement lines are known.
  return (
    (position === change.start || position === change.end) &&
    !change.deletionOnly &&
    (change.end - change.start !== 1 || change.lines.length !== 1)
  )
}

/**
 * Merge complete lines changed independently against the same baseline.
 * Line endings, blank lines and the presence of a final newline are preserved.
 * Different edits of one original line, competing insertions at one position,
 * and ambiguous overlapping ranges refuse the entire merge. Identical changes
 * apply once. A successful result is still a draft; this function saves nothing.
 *
 * @param baseline The common saved text both sides started from.
 * @param draft The person's current text.
 * @param incoming The other side's current committed text.
 * @returns All changes combined, or a refusal without a partial value.
 */
export function mergeTextLines(
  baseline: string,
  draft: string,
  incoming: string,
): TextLineMergeResult {
  if (draft === incoming || incoming === baseline) {
    return { merged: true, value: draft }
  }
  if (draft === baseline) return { merged: true, value: incoming }

  const lines = linesOf(baseline)
  const mine = lineChanges(lines, linesOf(draft))
  const theirs = lineChanges(lines, linesOf(incoming))
  const changes = [...mine, ...theirs].sort(
    (first, second) => first.start - second.start || first.end - second.end,
  )
  const merged: LineChange[] = []
  for (const change of changes) {
    const previous = merged[merged.length - 1]
    if (previous && overlaps(previous, change)) {
      if (previous.lines.length === 0 && change.lines.length === 0) {
        // The union of overlapping deletions removes each original line once.
        merged[merged.length - 1] = {
          start: previous.start,
          end: Math.max(previous.end, change.end),
          lines: [],
          deletionOnly: true,
        }
        continue
      }
      if (
        previous.start !== change.start ||
        previous.end !== change.end ||
        previous.lines.length !== change.lines.length ||
        previous.lines.some((line, index) => line !== change.lines[index])
      )
        return { merged: false }
    } else {
      merged.push(change)
    }
  }

  const output: string[] = []
  let cursor = 0
  for (const change of merged) {
    output.push(
      lines.slice(cursor, change.start).join(''),
      change.lines.join(''),
    )
    cursor = change.end
  }
  output.push(lines.slice(cursor).join(''))
  return { merged: true, value: output.join('') }
}
