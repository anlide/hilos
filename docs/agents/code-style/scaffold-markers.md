# Scaffold and Deliberate-Keep Markers

Code added ahead of its first caller — an unused API surface, an extension point
with no current consumer, a placeholder slot / param / column — must be flagged
explicitly as a scaffold. Otherwise a dead-code sweep (a person, or another
agent) reads it as genuine dead code and removes it; the deliberate "for later"
intent is invisible without a marker.

## Rule

When you leave code wired but intentionally uncalled by design:

- mark it **at the code site** — an in-code comment
  (`// SCAFFOLD: not wired yet — <why>`) or a clear PHPDoc / TSDoc note stating
  the deliberate "for later" intent;
- and call it out wherever you describe the change, so a reviewer reads it as a
  scaffold, not an oversight.

The deliberate-keep intent must live at the code site, not only in a commit
message or chat — those are invisible to the next sweep.

## Related: never silently drop config-data

The same spirit applies to data/config files that look unused: do not delete one
on sight — park it with a `DO-NOT-DELETE: <reason>` note. Make keep-on-purpose
explicit so a cleanup does not nuke it.
