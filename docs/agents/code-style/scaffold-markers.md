# Scaffold, Deliberate-Keep and Sunset Markers

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

## Related: a stub that is standing but sentenced

The third marker is the reverse of the first two: code that stays, is called,
and is on its way out. A test stub the stand's emulated services will replace
([stand-services.md](../stand-services.md), "A Stub Is a Sunset, Not a Tool")
carries one line in its class docblock:

```
SUNSET: <what replaces it> — <HIL-key or "no leaf yet">
```

The line lives on the code site for the same reason the other two do: the
reader who opens the stub — about to lean on it, extend it, or copy it for the
next channel — has not read the document that sentenced it. It is removed by the
leaf that removes the stub, and by nobody earlier; a sunset line on a class that
is gone is not a case, and one on a class that stays is the whole point. No new
test stub is added under any marker: a direction that needs to be proved on the
stand gets a resident there instead.
