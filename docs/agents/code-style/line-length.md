# Line Length

Read this when a line runs long, when a class declaration or a call no longer
fits, or when the `LINE-LENGTH` guard fails on your change.

## Core Rule

A PHP line is at most **150 characters** wide. Width is counted in characters,
not in bytes.

**Checked automatically: `LINE-LENGTH`**, see
[automated-checks.md](automated-checks.md).

The rule is lexical and says nothing about what a long line contains: it reports
the line and leaves the break to you. The shapes below are the breaks this
repository already uses — none of them is new here.

## Why 150

The threshold is not a taste. It had to be low enough to catch the declaration
that raised the rule: `abstract class DaemonManager` and the interfaces it
implements. Every figure below was measured on the tree of 2026-08-08, when the
limit was chosen, and a declaration only grows — that same one stood at 194
columns by the time it was broken.

At that measurement the declaration was 165 columns and the `Logger` lines beside
it 170, so 180 would have caught neither. Of the thresholds weighed, 150 was the
lowest that catches them without turning the rule into a reformatting project:
120 would have reported 804 lines in 274 files against the 109 that 150 found.

Bytes are the wrong unit. An en dash in an English comment weighs three bytes and
occupies one column, and by bytes the rule would report lines that fit on the
screen — some 900 lines in this repository carry a multi-byte character.

## The one exception: heredoc and nowdoc

A line inside a heredoc or nowdoc body is not checked. Breaking a line there puts
a `\n` into the string itself — into an LLM prompt, into the body of a SQL
statement — and a rule about the shape of code may not ask for content to change.

This is syntax, not a suppression marker: it cannot be written above an arbitrary
line to buy silence for it. If a long line is genuinely data rather than code, a
heredoc is what says so.

The exemption stops at that syntax, and the gap is real: a long line inside an
ordinary multi-line quoted string is reported, and the only way out is to edit
the string. For SQL that is harmless — the break lands where the engine ignores
whitespace, which is what the analytics INSERT does. For text whose newlines are
data, it is not, and there the answer is to move the text into a heredoc rather
than to break it in place.

## How to break a line

PSR-12, which this repository already follows. No new style is introduced here.

**A call whose arguments no longer fit** — one argument per line, indented one
level, trailing comma:

```php
$this->logger->warning(
    'peer dropped before the snapshot was answered',
    ['peer' => $peer->id(), 'waited' => $waited],
);
```

**A class declaration with several interfaces** — one interface per line:

```php
abstract class DaemonManager extends BaseManager implements
    MembershipObserver,
    LeadershipObserver,
    PlacementObserver
{
```

**An interpolated string** — concatenate at the seams of meaning, and keep each
piece a whole thought rather than splitting mid-phrase:

```php
$message = "worker {$worker->id} left the pool after {$seconds}s"
    . " while {$pending} messages were still queued";
```

**A docblock line** — wrap the description onto the next line of the block; the
tag and its type stay on the first:

```php
/**
 * @param string $host Host the connection was opened to, spelled out at whatever
 *     length the reader needs
 */
```

**A long condition** — break before the operator, one operand per line:

```php
if ($this->isLeader()
    && $this->cluster->hasQuorum()
    && !$this->protectedMode->isEngaged()
) {
```

## Do not

- Shorten a name to make a line fit. A cramped identifier costs every reader; a
  break costs one line.
- Break inside a heredoc to satisfy the rule — the rule does not ask for it, and
  the break would change the data.
- Reformat a whole file because one of its lines was reported. The fix is the
  line, and a file-wide reflow buries it in a diff nobody can review.
