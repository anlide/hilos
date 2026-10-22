# Framework change sources, format 1

`../catalog.json` is the immutable genesis: each schema unit known at the
catalog's introduction has an `upSha256` and `downSha256` of the real stubs at
that moment. Bootstrap-owned units have both values `null`, since migration
initialization owns their DDL. The schema unit registry remains
`FrameworkSchemaCatalog`; the genesis neither defines tables nor duplicates
feature requirements. Do not rewrite genesis when a stub changes or a unit is
added, and do not reconstruct historical ALTER statements. Append a change group
and update the current stubs instead; a unit added after genesis is opened by
its introducing part (below).

A group is `Change/<id>/change.json`. Its id is a UTC timestamp with six
microsecond digits, `YYYYMMDDHHMMSSffffff_HIL-<ticket>_<slug>` (20 digits before
`_HIL-`, a positive ticket number and a lowercase alphanumeric/underscore/hyphen
slug starting with a letter). The directory name and manifest id must agree.

```json
{
    "format": 1,
    "id": "20261020120000000000_HIL-1518_example",
    "parts": [
        {
            "unit": "identity",
            "up": "auth/hilos_identity.sql",
            "down": "auth/hilos_identity_down.sql",
            "requires": [
                {"changeId": "20261019120000000000_HIL-1518_previous", "unit": "identity"}
            ],
            "afterStubUpSha256": "<64 lowercase hex digits>",
            "afterStubDownSha256": "<64 lowercase hex digits>"
        }
    ]
}
```

A part's identity is the pair `(changeId, unit)`, represented internally by
`changeId/unit`. A group contains at most one part per unit, and each group id
is unique across manifests. `requires` lists exact identities; neither a
filename nor a timestamp implies a dependency. Each part changes one schema
unit. Split work touching several units into separate parts. A shared unit is
selected once by the feature unit union, whatever folder contains its SQL.

Both SQL directions are required, plain SQL and immutable after publication.
Paths are relative to the group. Absolute paths, dot/empty segments, backslashes,
URI/drive prefixes and physical resolution outside the group (including through
a symlink) are refused. Manifests are read through `FsPath`, as are SQL bodies.

Selected parts must have all dependencies selected. Missing dependencies and
cycles are refused. Ready independent parts are taken in lexicographic identity
order. Parts changing the same unit must form an explicit dependency chain
(transitive dependencies may cross units). Independent branches of the same
unit are refused; a later timestamp never resolves an ambiguous ending. The
last part in that chain declares the current stub hashes in `afterStub*Sha256`.
With no parts, the current stub must match genesis. Bootstrap-owned parts have
both `afterStub*Sha256` values `null`.

## A unit after genesis

A unit absent from genesis, such as a framework table added later, is opened by
exactly one part with `"introducesUnit": true`. The field is optional and only a
JSON boolean; absent means `false`, so earlier manifests read unchanged. The
introducing part heads the unit's explicit chain: every later part of the unit
requires it directly or transitively. Its `up` creates the unit, and its
`afterStub*Sha256` are the unit's first snapshot; the last part of the chain
declares the current one, as for any unit. The whole chain of such a unit,
dependencies in other units included, is checked when the catalog is read,
whether a project selects the unit or not.

Refused when the catalog is read: a unit missing from genesis with no
introducing part (`Framework genesis misses schema unit …`), even when ordinary
parts carry stub hashes for it; a second introducing part of one unit; an
introducing part that is not the head of its chain; and an introduction of a
genesis unit or of a bootstrap-owned unit.

A project that selects the unit receives its current stub as an `install` pair
recording every part of the unit, the introducing one included, like any other
newly installed unit; the introducing SQL is not replayed. The flag belongs to
the source manifest only: origin part references and headers keep the fields
below, and their provenance names the exact immutable part.

## Origin pair, format 1

Each generated direction begins with the identical line, followed by LF:

```text
-- hilos-framework: {"format":1,"kind":"install","units":["identity"],"parts":[],"upSha256":"<digest>","downSha256":"<digest>"}
```

The exact fields are `format`, `kind`, `units`, `parts`, `upSha256` and
`downSha256`. Kinds are `adopt`, `install`, `change` and `baseline`. Units are a
nonempty unique list of known schema unit keys. Parts are a unique list of
`{changeId, unit, upSha256, downSha256}` references to immutable SQL sources,
with each part's unit present in `units`.

A `change` names exactly one part and its one unit; its body digests must match
the immutable source. An `install` or `baseline` names exactly the parts already
included in the copied current form of its units. At writing time take that
complete set from `partsForUnits()` / `selectedParts()`. Historical copies keep
their recorded set: verification compares their own fixed body digests, never a
newer stub or a maximum change id. Genesis `adopt` has `parts: []` and the complete
verified existing composition of its project, which may be a subset of the
framework's known units. It does not claim that absent units are installed.
This API does not provide an adoption procedure or a baseline generator.
The parser accepts a future composite baseline with multiple units and parts.

`FrameworkMigrationOrigin::canonicalSql()` is the shared read/write
canonicalization: text must be valid UTF-8 without a leading BOM; CRLF and bare
CR become LF. Every other byte is preserved, including whitespace and the
presence or absence of the final LF. There is no trimming, Unicode normalization
or added final newline. SHA-256 uses lowercase hexadecimal. `bodyHash()` hashes
only the canonical SQL body, excluding the first origin line and its LF, and
refuses a reserved origin comment inside that body. Use `forBodies()` and
`header()` when writing; emit the same header in both directions and append the
canonical bodies. SQL source files and stubs contain no origin lines.

`parsePair()` verifies both first lines, format, membership shapes and body
hashes. `FrameworkMigrationCatalog::verifyPair()` additionally checks the known
units and immutable source references. `coveredParts()` assembles exact project
coverage and refuses duplicate part provenance or repeated installation of one
unit; `missingParts()` subtracts this exact set from selected sources. Dependencies
of separate change files are checked together when assembling project coverage.
`verifyCurrentStubs()` is separate: it checks the selected dependency graph and
the current declared stub snapshot without invalidating older generated copies.
