# Spelling

Read this before writing any English text in the codebase: identifiers, string
keys, routes, visible UI copy, comments, and PHPDoc/TSDoc.

## Rule

Use American English spelling everywhere.

Checked automatically: `SPELLING` — the six pairs of the table below, each
together with its own word forms, on both sides of the PHP↔TypeScript boundary
(see [automated-checks.md](automated-checks.md)). A form counts only when it
keeps the whole British word and adds letters after it: `serialises` is caught,
but `serialising`, `organising` and `licencing` drop a letter of the word and
pass unseen, and so does anything outside `.php`, `.ts`, `.tsx`, `.vue` and
`.html` — markdown, stylesheets, scripts. A green run does not clear those.

```
license   not  licence       color     not  colour
behavior  not  behaviour      serialize not  serialise
organize  not  organise       canceled  not  cancelled
```

This holds across every layer — class and constant names, variable names,
component selectors, subscription wire keys, URL paths, footer/menu labels,
page titles, comments, and doc blocks. One dialect for the whole repository.

The choice follows the surrounding ecosystem the framework already lives in:
the root `LICENSE` file (GitHub and SPDX recognize that name only), the
`"license"` field in every `composer.json`/`package.json`, and the proper name
of the license itself — the *MIT License*. Aligning the code to those removes
the British/American split that otherwise drifts page by page.

Do not let grammar reintroduce the British form: English distinguishes the noun
`licence` from the verb `license`, but American spelling collapses both to
`license`. Always write `license` (and `sublicense`), never `licence`.

## Exceptions

Preserve the original spelling, even when British, when it is not ours to
change:

- Quoted text, proper names, and third-party identifiers (an external API field
  or library symbol spelled `colour`, a cited document title).
- The root `LICENSE` file name and SPDX/package `license` metadata fields —
  these are fixed by the tools that read them.
- `neighbour`, our own settled internal form. The pair `neighbor/neighbour` is
  not in the table above, so `SPELLING` is silent on the word by construction,
  and that silence is not an oversight to fix: the tree writes `neighbour` in
  over a hundred files of `framework/backend` and `framework/tests` against a
  handful with `neighbor`, one of them the name of the guard's own class,
  `framework/tests/CodeStyle/NeighbourDeclarations.php`. This is a named, closed
  exception for one word, not a category — "text the owner has approved" was
  rejected as one, because approved text is most of what this rule governs.
