# Comment language

## Rule

Write comments, doc blocks, markup comments and `FIXME(F-…)` parking labels
in English. Translate quoted words into English. If the original wording
matters, keep it in the ticket. Put examples that require Cyrillic in a string
literal or in test data.

## What counts as a comment

Checked automatically: `COMMENT-LANGUAGE` (see [automated-checks.md](automated-checks.md)).
The guard reports each physical comment line containing Cyrillic once.
It uses no language dictionary. Keep examples of violations in the guard's
fixture directories, which it excludes by exact path.

- **PHP and SQL.** PHP comments are the tokens `T_COMMENT` and
  `T_DOC_COMMENT`. The PHP/SQL roots are defined in `framework/tests/CodeStyle/ScannedRoots.php`;
  every `.sql` file under those roots is read, not just migrations. The guard
  judges loader comment lines whose trimmed text starts with `--` or `#`.
- **TypeScript and markup.** The shared scan in `framework/frontend/codestyle/textRoots.ts`
  covers `framework/frontend`, `demo/*/frontend/src` and `demo/*/tests/e2e` for `.ts`,
  `.mts`, `.tsx`, `.vue` and `.html`. It skips dependency/build and generated
  directories, `playwright-report`, `test-results` and the exact fixture path
  `framework/frontend/codestyle/fixtures`; see the [COMMENT-LANGUAGE registry entry](automated-checks.md#L45).
  It reads `//` and `/* */` comments in `.ts`, `.mts` and `.tsx`, including JSX
  `{/* */}`, and parses each `<script>` body in `.vue` and `.html` according to
  its `lang`, defaulting to TypeScript. Markup comments run from `<!--` to `-->`
  in comment position.
  In `.ts` and `.tsx`, Angular's grammar applies to a template literal assigned
  directly to `template` or `'template'` in the object literal passed as the
  first argument of `@Component({…})`. Other template literals containing
  `<!--` in those files use HTML grammar, as does every `.html` file.
  A `.vue` file uses Vue's single-file component grammar.

## Parking labels

The date in `FIXME(F-<k>, <date>)` determines whether to keep the original
wording. Read dates in `YYYY-MM-DD` or `DD.MM.YYYY` form. Leave a label dated
before 2026-11-21 as written until the parked work is resolved. This avoids
editing a test file solely to reword an existing label. In a comment, the
exemption covers only the physical line carrying the label. A label dated
2026-11-21 or later, or one without a readable date, must be in English.
There is no list of exempt labels.

Apply the same date rule to a parking label in a PHP string. A literal
starting with `FIXME(F-` and any literals concatenated to it with `.` form
one label, even when its explanation is in a later literal.

## What is not read

Ordinary string literals, test data and i18n catalogs are code. The PHP
parking label and markup comments in template literals are the string cases
covered above. A parking label written as a TypeScript string is outside the
check.

The guard does not follow `templateUrl`, constants, imports, spreads, reference
paths, or alias or namespace spellings of `Component`. Within the scanned roots,
those files and literals receive the ordinary HTML check. A comment after
Angular-only syntax there can go unreported. A quoted Angular template is an
ordinary string literal.

For markup parsing, the guard replaces each `${…}` substitution with neutral
text of the same length and preserves its line breaks. It still reads
TypeScript comments inside the expression. Nested template literals there are
data, except when a local declaration supplies its own initializer.

The guard skips trailing SQL comments and `/* */` blocks in `.sql` files.
It also skips Markdown, `.py`, `.sh`, `.js`, `.mjs`, `.scss` and `.yml` files.
The language of agent documentation is set in [rule-authoring.md](../rule-authoring.md).
