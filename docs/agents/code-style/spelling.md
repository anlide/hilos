# Spelling

Read this before writing any English text in the codebase: identifiers, string
keys, routes, visible UI copy, comments, and PHPDoc/TSDoc.

## Rule

Use American English spelling everywhere.

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
