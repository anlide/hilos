# Toy Wrapper

A router: every path written here is an address, whichever form it takes.

| Rule file | Read when... |
|---|---|
| [the routed rule](../catalog/routed.md) | a markdown link is the route |
| `catalog/routed-and-declined.md` | a backticked path is the route just as much |
| [the section of a live file](../catalog/routed.md#core-rule) | an anchor names a section, and the section is not checked |
| [the published spec](https://example.com/spec.md) | the target leaves the repository |

The wrapper also names `src/index.ts`, a file inside a frontend package root. It
is not addressable from the repository root, so it is not a route and not a
broken link either. Neither is `skill/toy run --now`: a span with a space in it
is a command, however much its first word looks like a directory.

A link may also climb out of the tree — [above the root](../../elsewhere/notes.md)
is not an address at all, and must not be clamped back down into the tree and
judged there under a name its author never wrote.

A citation carries a line number, and the number is not part of the address:
`catalog/routed.md:12` names a file that exists, and so does the range form
`catalog/routed.md:12-14`. A root-level file is an address of its own, the way
`agents.md` is in the repository — `root.md` here names nothing, and is caught.

A fenced block shows shapes instead of pointing at them, so nothing inside one is
a reference — neither a broken example nor a working route:

```md
[an example](../catalog/absent.md) and a path `catalog/orphan.md`
```

The broken example above must stay silent, and the orphan named inside the fence
must stay orphaned: being shown is not being routed.

Below are the three that must be caught: a [markdown link](../catalog/missing.md)
whose target is gone, a routed path `catalog/ghost.md` that names nothing, and a
link [reflowed across
lines](../catalog/nowhere.md) — one link still, and checked like one.
