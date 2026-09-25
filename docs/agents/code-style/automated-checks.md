# Automated Rule Checks

Read this when a rule in `docs/agents/*` should stop depending on memory, when a
guard test fails on your change, or when you are about to add a machine-checkable
rule.

## What is checked by machine

| Rule id | Enforces | Canonical rule |
|---|---|---|
| `CODE-FQN` | Executable code names a class by its imported short name, and a short name written there resolves to a class. Two halves under one id: a `T_NAME_FULLY_QUALIFIED` token anywhere in code, and a short name that resolves nowhere — judged in the positions where a miss is silent or distant: `catch`, `new`, `instanceof`, `extends`, `implements`, a static access, and a parameter, property or return type. A name resolves when it is imported, declared in this file, declared by a neighbour of the same directory, or known to the autoloader under this file's namespace. A backslash inside a string or a comment is out of scope by construction: neither tokenizes as a name. | [qualified-names.md](qualified-names.md) |
| `PHPDOC-FQN` | A docblock names a class by an imported short name. Three classes of message under one id: a leading-backslash fully qualified name, a name partially qualified against the current namespace, and a short name that is neither imported nor declared in this namespace. The first covers the type position of `@throws`, `@param`, `@return`, `@var`, `@property`, `@property-read`, `@method`, `@extends`, `@implements` (generic arguments and array shapes included, read whole), and the `{@see ...}` / `{@link ...}` cross-references; the other two are read in the cross-references only, where a head is unambiguously a symbol rather than a type expression. A file's own constants and enum cases, a neighbour of its namespace, and a constant of the global namespace resolve without an import. | [phpdoc.md](phpdoc.md) rules 9 and 12 |
| `RT-STATE-REACH` | `getStateCollection()`, `getStateItem()`, and `$this->stateCollection` are used only in files under `Database/` or `Runtime/`, whatever the caller's role. | [rt-state.md](../runtime/rt-state.md) |
| `RT-STATE-MUTATE` | Which rows a backing RT state collection holds is changed only through the base actions. Six spellings are read on a recognized receiver — `add()`, `remove()`, `clear()`, `unset($collection[$id])`, `$collection[$id] = $state` and `$collection[$id] ??= $state`, the last three because the store routes them into `add()` and `remove()` — and they are legal only in the four files the rule lists: the two base `RtActions`, the sync applicator and the snapshot. The store's own row array is the second road to the same place, so `$this->states` is written only by `RtStates`, and reading it is not judged. A receiver is recognized as `getStateCollection()` called on anything, `$this->stateCollection`, `$this->_stateCollection`, or a variable one of those is assigned to directly, `??=` counting as such an assignment; `$this->states` is judged by that same pair of operators, and `??` alone is a read on either road. No baseline and no in-comment marker: a fifth legal writer is a line in the rule, with its reason. Every root. | [rt-state.md](../runtime/rt-state.md) |
| `DB-OBJECT-MUTATE` | Which rows a DB object store holds is changed only through the ArrayAccess door of `Objects`, which announces: `$this[$id] = $object` for a row that is born and `unset($this[$id])` for one that goes. The store's own row array is the road that bypasses the announcement, so `$this->objects` is written by two files only — `Objects`, which owns the door and the silent `hydrate()` seam, and `ObjectCollection`, which is no subclass of it and keeps a private array of its own under the same name. Five spellings are judged: a key write, an append, a replacement of the whole array, `unset()`, and a coalescing assignment of either a key or the whole array. Reading is not judged at all — `??` on its own stays a read — and neither is writing a field of a row already there. The receiver is read lexically as `$this->objects`, so a class that is no store but keeps an `$objects` property of its own is reported too — the way out of such a hit is to rename that property. No baseline and no in-comment marker: a third legal writer is a line in the rule, with its reason. Every root, tests included. | [object.md](../orm/object.md) |
| `VIEW-WRAPPER-BIND` | A view wrapper holds the row it was built from, never the variable that row was handed over in. Two halves under one id: `$this-><name> = &$variable`, the binding itself, and a parameter declared by reference, the signature that makes a caller produce the variable to bind to. The first is judged on every root — the `readonly` on `DbItem::$_object` and `RtItem::$_state` already refuses it at runtime, but a new wrapper hierarchy has no such field yet; the second only under `Database/View/`, `Database/Actions/`, `Runtime/View/` and `Runtime/View/Actions/`, since outside the wrapper layer a reference parameter is an ordinary accumulator or out-parameter. A closure's `use (&$captured)` clause is out of scope by construction: the walk reads parameter lists, and a capture list is not one. No baseline and no in-comment marker: there is no legitimate case to admit. Every root for the property write; the View zones for the parameter. | [collection-iteration.md](../orm/collection-iteration.md) |
| `ERROR-SUPPRESSION` | `@` silences a warning only under a `// warning-suppressed: <reason>` marker on the line directly above the call. Production roots only. | [error-suppression.md](error-suppression.md) |
| `FS-SEAM` | A file primitive that owes an exception is called through `Hilos\Fs\FsPath`, the one file the rule exempts, matched by the tail of its path. Three signs, at most one hit per call: a file opened under `@`, whatever the next line does; a suppressed primitive addressed by a path whose checked failure reaches a `throw` — either the call stands in the condition of an `if`, or it is assigned and the next statement is such an `if` over that same variable; and a path primitive called without `@` whose `false` result is tested — negated, compared to `false` on either side, the left of `?:`, a ternary's condition, the bare condition of an `if`, `elseif` or `while` alone or in a chain, or a variable whose first read after the assignment, within the enclosing block, is such a test — a branch no Hilos process reaches, since the managers' handler ends the process on the warning first. The first two signs judge the call the `@` covers, an assignment target standing in between included, so `@$h = fopen(...)` reads as `$h = @fopen(...)` does, and the third reads such a call as suppressed; list destructuring under `@` is not read at all. `realpath()` and `glob()` fail silently and are outside the third sign; a root declared standalone is outside it too. A result nobody examines is class D and stays silent; stream and socket primitives are not judged, since class B lives there. Production roots only. | [error-suppression.md](error-suppression.md) |
| `RANDOM-SOURCE` | A secret is drawn from `RandomHelper::secureBytes()` / `secureHex()`, which throw when the entropy source refuses. The tolerant `bytes()`, `hex()` and `integer()`, which fall back to `mt_rand()`, are callable only from a file the rule itself lists — an inventory of every caller, not a guess at which zones hold secrets. Production roots only. | [random-source.md](random-source.md) |
| `BLOCKING-RESOLUTION` | A host name is never turned into an address by a call that blocks the process: `gethostbyname`, `gethostbynamel`, `gethostbyaddr`, `dns_get_record`, `dns_get_mx`, `checkdnsrr`. Read in call position only, so one of these names in a string, in a comment, or worn by a method is not a hit; `gethostname()` is not in the family, being a `uname(2)` read with no resolver behind it. The list of allowed files is empty and is meant to stay so. Every root, tests included. | [blocking-resolution.md](blocking-resolution.md) |
| `PROCESS-FORK` | The PHP process is never forked by a call to `pcntl_fork` or `pcntl_rfork`. Read in call position only, so one of these names in a string, in a comment, or worn by a method is not a hit; `pcntl_exec` is not in the family, replacing the image rather than forking, and `proc_open` is the canonical isolated-process primitive. The list of allowed files is empty and is meant to stay so: adding an entry owes a leaf ticket key and a reason, with no baseline and no in-comment marker. Every root, tests included. | [process-fork.md](process-fork.md) |
| `MAGIC-REPEAT` | The same number is written twice or more in one file. Numbers inside a `const` declaration, inside the value of a keyed array entry — which is what takes a data catalog out of the rule, entry by entry — and the structural `0`, `1`, `2` are not counted. Production roots only. | [magic-values.md](magic-values.md) |
| `EMPTY-STRING-SENTINEL` | An empty string literal is minted where a value is absent: `??` falls back to it, a ternary branch hands it back, or a `match` `default` arm does. Inside the checked zone only, and unless a `// external-boundary: <reason>` marker on the line directly above names the outside source the value comes from. | [method-contracts.md](method-contracts.md) |
| `PAYLOAD-SENTINEL` | A judged reader reads an absent key as a value. Eight method names are judged, by name wherever they stand, in two groups: `fromArray()`, `fromJson()`, `fromRow()`, `hydrateBase()` and `hydrateOwn()` are handed a whole frame or row, `applyDiff()`, `applyBaseDiff()` and `applyOwnDiff()` a partial one. Two findings. A minted stub — `''`, `0` or `0.0` fallen back to with `??`, handed back by a ternary branch, or returned by a `match` `default` arm — is reported in either group, and the group picks the cure the report names: refuse the payload or let the field be null, against read it with `patch*`. A call of the `optional*` family, matched by the prefix of the name, is reported in a diff body only: it answers null to a key the diff does not carry and clears a field it never touched. `?? null` and `?? []` are legal in both groups, and a `// external-boundary: <reason>` marker on the line directly above legalizes one minted stub — the misread diff key has no legitimate case and no marker. Every root. | [method-contracts.md](method-contracts.md) |
| `WIRING-REFUSAL-SWALLOWED` | A broad `catch` around a read of `Hilos::$db` or `Hilos::$rt` turns a wiring defect into an ordinary answer. Broad is `Throwable`, `Exception` and `HilosException`, the three that stand above both refusal species; a narrower family that happens to contain one, such as `RtBaseException`, is not judged. A read is a collection named after the arrow — `Hilos::$db->users`, `Hilos::$rt?->connections`, and the dynamic `->{$key}` — because that is what reaches the read guard in `__get()`, plus the one method of the context that reaches the same guard, `Hilos::$db->getObjectCollection()`; every other method of the context is not, including `reHydrateDbBackedCollections()` and the object layer's own unjudged entrance `mountedObjectCollection()`. Three ways out: narrow the catch, name the refusal in a clause of its own above the broad one (usually to rethrow it), or write `// read-refusal-swallowed: <reason>` on the line directly above the catch — an empty reason is a hit of its own, as it is for `@`. One hit per `try`. Production roots only. | [wiring-refusals.md](wiring-refusals.md) |
| `WIRE-KEY-CASE` | A field key that crosses PHP → wire → TS is spelled camelCase. Two halves under one id: PHP judges a constant named in camelCase, TypeScript a constant named `<NAME>_FIELD` and the entries of an `as const` `*RowKey` map. A value that is a reference to another constant is judged where the key is spelled out. | [cross-layer-field-names.md](cross-layer-field-names.md) |
| `LINE-LENGTH` | A PHP line is wider than 150 characters. Width is counted in characters and not in bytes, so a multi-byte dash costs one column. A line inside a heredoc or nowdoc body is not checked: a break there would land in the string itself. | [line-length.md](line-length.md) |
| `MEMBER-INDENT` | A member of a class, interface, trait, enum, or anonymous class body — visibility, abstract, final, static, readonly, var, function, const, enum case, trait use, attribute, docblock — is indented by exactly four spaces relative to the line that opened the body; a tab is a violation. | [code-style.md](../../code-style.md) |
| `THROWS-PROPAGATION` | An exception a callee documents is named by the caller's own `@throws` too, unless an enclosing `catch` swallows it; and an implementation does not document an exception the declaration it overrides is silent about. A `throw new X` is judged as its own callee. Only calls whose target is known without inferring a type; an index on such a receiver counts as the call it is, reaching one of the four `ArrayAccess` methods; a magic property's class may be named by a constant on its receiver or by a class-level `@property-read` or `@property` tag, the record on the class itself outranking the one it inherits; a private helper is walked through rather than trusted. | [phpdoc.md](phpdoc.md) |
| `THROWS-ORPHAN` | A method does not document an exception its body cannot throw: every `@throws` tag is related, through the exception hierarchy in either direction, to something the body lets out — a callee's contract, a `throw new`, a private helper walked through. The claim is made only where the body was read whole, so the rule is silent on four grounds, and the row names them because a green run is otherwise read as coverage: a call that did not resolve, which includes every entry the index sees and cannot follow — a function, a closure, a call on what an expression returned, a callee, member or class held in a variable, a `throw` of anything but `new` — as well as a magic step whose class declares no `__get()`, and an undeclared static step; a body with no call, `new` or `throw`, which is an extension point; a tag the inherited contract declares; a tag that covers the tag of an override. A magic reader's `@throws`, inherited or declared locally, joins the reachable exceptions before the enclosing `catch` is subtracted, including through a private helper. A tag covered by a wide `__get()` contract counts as alive — `HilosException` can back any tag in its hierarchy — without asking callers to propagate that contract. A constructor and a private method get the first two grounds only: nothing inherits a constructor's contract, and nothing overrides a private method. A trait method is judged in each class using it and reported where its tag is written; a trait no class uses is not recognized as one and is judged on its own body. Every production root. | [phpdoc.md](phpdoc.md) |
| `PAGE-REACH` | Every concrete page says whether the browser navigates to it, and a page that says it does not may not lean on `READS_DB` or `READS_RT`. The answer is the `REACH` constant, resolved up the parent chain, so a base answers for its whole branch. Four findings: a page for which nothing resolves, an `ACTION_HOST` whose `READS_DB` resolves to a non-empty list — that list is taken up on a page subscription only, so those reads belong in `DbContext::processWideReadCollections()` — an `ACTION_HOST` whose `READS_RT` resolves to a non-empty list, which is taken up on the same subscription that never comes and has nowhere else to go, since the frozen-replica mark it exists for has no screen to mark — and one of the two common roots carrying anything but `UNDECLARED`, which would declare the whole repository at once. Abstract classes are never required to answer. No baseline and no in-comment marker: the two roots are a line in the rule, with their reason. Every production root. | [subscriptions.md](../signals/subscriptions.md) |
| `E2E-BOX-MEASURE` | A demo spec takes geometry bookmarks from the shared toolbox. Every direct `boundingBox()` or `getBoundingClientRect()` call in `demo/*/tests/e2e` is reported, helpers included, once per call in source order. TypeScript only; there are no allowed files. Names in strings, comments or member reads are not calls. Three boundaries remain: indexed or aliased method calls are not read; scroll measurements and document height are outside the box rule; `framework/frontend/e2e` is outside the scan because it owns the measurements. | [testing-strategy.md](../frontend/testing-strategy.md) |
| `E2E-PAGE-GOTO` | An e2e spec opens a page through `gotoPage()`, never through Playwright's `goto`, which waits for the document and not for the subscription's answer. TypeScript only; the `helpers/page.ts` that owns the wrappers is the one place the call is allowed, and the one address it may carry elsewhere is a stand resident's screen, written from the `STAND_GATEWAY_URL` imported from `framework/frontend/scripts/standGateway.mjs`. | [testing-strategy.md](../frontend/testing-strategy.md) |
| `SPELLING` | The six pairs of `spelling.md` are written in American English wherever a file writes English — a name, a string, a comment, a doc block, a template. The word list is the table of that document and nothing else: `licence`, `colour`, `behaviour`, `serialise`, `organise`, `cancelled`, each taken together with its own forms (`behavioural`, `colours`, `serialises`), with no seventh pair and no rule of endings behind it. The boundary is an identifier boundary, not a whitespace one: a match opens on a non-letter or on a camelCase hump — a lowercase letter, a digit or an underscore before a capital — so `PASSKEY_CANCELLED_*` is a hit where `\b` would be silent, and it closes after the trailing lowercase letters; it does not open inside a word, so `discolouration` is silent, and `neighbour`, having no pair in the table, is silent by construction. Two halves under one id: PHP reads every text-bearing token of every root, suites included; TypeScript reads the source text of `framework/frontend`, `demo/*/frontend/src` and `demo/*/tests/e2e` (`.ts`, `.tsx`, `.vue`, `.html`), tests included. Left out: the checkers' fixtures; the two files of each half that hold the table and pin the report — the rule's own source and its fixture test; and, beside the dependency and build directories every checker skips, the `playwright-report` and `test-results` an e2e root carries after a run, since this is the first checker to read `.html` there and a generated report is not authored text. The rule document itself is markdown and outside both halves by construction. One record per occurrence, and the report names the American form in the case and with the tail of the one found. | [spelling.md](spelling.md) |
| `STYLE-SHEET-HOME` | The Bootstrap Sass layer is the only home a custom style declaration has. Three faces under one id: a `.css` / `.scss` / `.sass` / `.less` file outside the sanctioned list — one `hilos-styles.scss` per view package — a `<style>` block in a Vue SFC, and `styles:` or `styleUrls:` on an `@Component`. A stylesheet is judged by its path alone, whether or not anything imports it. Not judged: `angular.json` `styles` and an `index.html` `<link>`, both of which reference third-party stylesheets under `node_modules`, and a third-party stylesheet is not a hand-authored one. TypeScript only. | [styling-rules.md](../frontend/styling-rules.md) |
| `STYLE-INLINE` | An element carries no hand-authored declaration. What is judged is the **name** of every property a site sets, not static versus dynamic: the site is legal only when every one of them is a CSS custom property (`--*`), the one channel a computed value has. Read in every spelling the three view frameworks offer — Vue's `style` / `:style` / `v-bind:style`, Angular's `style` / `[style]` / `[style.prop]` / `[style.prop.unit]` / `[ngStyle]` / `ngStyle`, React's `style={…}` — and in the imperative forms `el.style.<prop> = …`, `el.style.setProperty()` and `el.style.cssText`. A set of names that cannot be read where it is written — an identifier instead of an object literal — is a violation with a message of its own, since passing it would make the ban one indirection deep. A cast (`as`, `satisfies`) or parentheses around an object literal leave its names readable and are judged as if they were not there — React needs the cast, since its `CSSProperties` types no custom property. Reading `.style` is not judged. One violation per site, not per declaration. TypeScript only. | [styling-rules.md](../frontend/styling-rules.md) |
| `ICON-NAME-EXISTS` | Every Bootstrap Icon class name exists in the installed icon set, and the declared dependency floor matches the installed version. Two findings under one id: (1) an icon name `bi-*` not present in the installed `bootstrap-icons` set, and (2) a `bootstrap-icons` range in `package.json` whose declared lower bound does not match the version installed on disk. Scans `.ts`, `.tsx`, `.vue`, `.html`, `.php` across `framework/frontend`, `framework/backend`, `framework/tests` and `demo/*/{frontend,backend,tests}`, test suites included. Three declared blind spots: dynamically concatenated icon names (`'bi-' . $x`, `bi-${x}`) are invisible to lexical matching; the rule runs in frontend suites rather than PHP runs, so backend catalog icon defects are surfaced by frontend guards; and the installed icon set is what names are judged against, while version divergence is caught by the declaration check. | [styling-rules.md](../frontend/styling-rules.md) |
| `DISABLED-TITLE` | A control that can be disabled carries no `title` other than its accessible name. What is judged is not the pair but the gap between them: the element carries `disabled` or `loading` — `LoadingButton` turns its own button off while it loads — and a `title` whose text, runs of whitespace collapsed, differs from the text of its `aria-label`, or it has no `aria-label` at all. Both sides are compared as written: a static value and a quoted string by their text, any other expression by its source, so `row.keep ? 'Unpin' : 'Pin'` written on both is one name and a ternary differing in one branch is a hit. Read in every spelling — Vue's `x` / `:x` / `v-bind:x`, Angular's `x` / `[x]` / `[attr.x]`, React's `x={…}` — in SFC templates, Angular component templates, `.html` files and JSX. One violation per element, on the line of its `title`. TypeScript only. | [accessibility.md](../frontend/accessibility.md) |
| `MODAL-FOCUS` | Every `HilosModal` / `hilos-modal` mount names where focus lands: a `data-autofocus` mark in that mount's own subtree in the same file, or a static `initialFocus` / `initial-focus` of `"dialog"` or `"inner"`. Bound forms — Vue's `:initial-focus` / `v-bind:initial-focus`, Angular's `[initialFocus]`, React's `initialFocus={…}` — are reported, not honoured. `'inner'` is trusted: nothing checks that the component drawing the body carries a mark. Production roots only (the SDK packages' `src` and each demo's `frontend/src`), which is what leaves the suites out without listing them. One violation per undeclared mount, on the line of its opening tag. TypeScript only. | [accessibility.md](../frontend/accessibility.md) |
| `BROWSER-VALUE-DECLARED` | A file that leaves a value in the visitor's browser declares it there. What is judged is the write: a `setItem()` call, on whatever receiver — the two framework files that keep a value today both write through a local variable holding the store, and `setItem` is a name the browser gave to storage alone — and an assignment to a `cookie` property, `=` and `+=` alike. The declaring form is a `browserValue(` call, and a declaration written any other way is invisible here, the way it is for `WIRE-KEY-CASE`. File-level, reported once at the first write, because that is the granularity of the rule itself: declared beside the write. TypeScript only, so a `.vue` SFC is a blind spot, and production roots only — the SDK packages' `src` and each demo's `frontend/src`, which is what leaves tests and e2e specs out without listing them. One file is excluded by path: the sweep in `browser/browserValues.ts`, which expires a cookie by writing it back and declares nothing because it is what spends the declarations. | [core-and-connection.md](../frontend/core-and-connection.md) |
| `VUE-TEMPLATE-REF` | Inside the `<template>` block of a `.vue` file, a field of a ref-bearing value is read through `.value` and never bare: Vue unwraps a ref that is a top-level binding of `<script setup>` and unwraps nothing a level below it, so a ref held as a field of a plain object stays an object — and an object is always truthy. Ref-bearing is named, not inferred: a value assigned from a composable the rule lists (`useTrackedAction`), and a prop declared with a type it lists (`TrackedAction`); widening the rule is one line in one of those two lists. A call is not a read, so `action.run(handle)` is silent. The whole template is judged, interpolations included, where Vue would have unwrapped by itself — the exception is real and a rule saying "bare in the moustaches, `.value` in an attribute" is one nobody holds in their head. Two blind spots the rule declares: a value that reached the file any other way — destructured, imported, injected — and a composable or type nobody listed. Frontend only, `.vue` only; neither other view layer has the shape. | [vue-template-refs.md](vue-template-refs.md) |
| `SHELL-PARITY` | A surface represented in Vue has a counterpart in React and Angular. One traversal of each framework SDK `src` reads `.vue`, `.ts`, `.tsx` and `.html`, excluding tests, specs, dependency/build directories and the checker's exact fixture path; demos are outside the scan. It compares static `data-id`, Vue `:data-id` / `v-bind:data-id`, Angular `[attr.data-id]` and React `data-id={…}` sites, and the `dataId` prop through which React and Angular hand a name to an SDK component that Vue passes as a `data-id` attribute — static `dataId="…"`, Angular `[dataId]="…"`, React `dataId={…}` — preserving exact literals and reducing a template or Angular concatenation with a literal prefix to `prefix-*`; opaque expressions and a template beginning with interpolation are skipped. It also compares UpperCamelCase value exports from each package's root `index.ts`, ignoring type and lowercase exports. Vue is the one-way reference, so a surface first added outside Vue is invisible, and every unmatched Vue occurrence is reported separately. Other declared blind spots are Angular `templateUrl`, opaque handles, behavioral drift behind equal handles, and a name handed over as a `dataId` prop, which is taken on trust: nothing checks that the component renders it. TypeScript only. | [multiframework-core.md](../frontend/multiframework-core.md) |
| `DOC-ROUTE` | Every file of this catalog, at any depth, is mentioned by at least one `skills/*/SKILL.md`, or declines a route in itself and says why. A file that is both routed and declining is reported the same way. | [rule-authoring.md](../rule-authoring.md) |
| `DOC-LINK` | A local reference in the agent docs names something that exists. In a skill wrapper both a markdown link and a backticked path count as one; in a document only a markdown link does. | [rule-authoring.md](../rule-authoring.md) |
| `SKILL-HEADER` | Only the header of `skills/*/SKILL.md` is judged. Non-empty `name` and `description` are required. An unquoted value must not carry `: ` or ` #`, must not end with a colon, and must not open with a YAML indicator. Quoted values and block scalars are legal and are not judged inside. Extra keys are not forbidden. No baseline. | [rule-authoring.md](../rule-authoring.md) |
| `SECRET-IN-QUERY` | A query parameter is read only under a name the rule lists. It reads the by-key readers of `RequestQueryParams` — `getString()`, `requireString()`, `requireStringMatching()`, `has()` — and matches the text of the argument as written at the call site, because a token walk cannot resolve another class's constant. Two names are listed today, each with its reason; `toArray()` is out of scope. Every root. | [secret-in-query.md](../antipatterns/secret-in-query.md) |
| `TRUTH-SOURCE-CLAIM` | Ownership of a collection is declared on the class, in `OWNS_DB` or `OWNS_RT`, never claimed in a call. The direct road into the ownership registry is what is read: `register()` reached on one of five names — `TruthSourceRegistry`, `RtTruthSourceRegistry`, `AbstractTruthSourceRegistry`, and the `self` / `static` of a class extending one, which is how a subclass of the registry would otherwise walk past. Four files may reach it, matched by the tail of their path so that the fixture proving the resolver silent is judged the way the resolver is: `OwnershipDeclaration`, under the reason that it lays the claim down having read the constant off the class, and the three registries, under the reason that `register()` is their own method. Not caught, each a different mechanism: `registerCreate()` / `unregisterCreate()`, the right to bring a row into being; `registerDaemon()` / `unregisterDaemon()`, a daemon's claim rather than an agent's; `unregister()` / `unregisterAgent()`, giving a claim back. Production roots only. | [truth-source.md](../architecture/truth-source.md) |

`RT-STATE-MUTATE` recognizes its receiver lexically, and three narrownesses
follow from that. A collection that reaches the code some other way — as a method
parameter, or out of a getter named something else — is invisible to the rule,
and the aliases it does read are collected over the whole file rather than per
scope, because `RtSnapshot` mutates its alias from inside a closure declared
below the assignment; a file where one variable name means the collection in one
method and something else in another is therefore judged by the name. The
allowed places are paths relative to the scanned root, so a demo that gave itself
a `Runtime/View/Actions/Collection/RtActions.php` would earn the same permission
the framework's base actions have — no demo carries such a file today. And the
rule judges membership only: writing a field of a row that is already in the
collection is legal and is not read at all.

The second road is recognized by the property name alone, which is where the rule
reaches past its document: a class that is no state collection but keeps a
`$this->states` of its own — a list of anything — is reported with the same
sentence about `RtStates`. No such site exists in the scanned roots today, and
the way out of a hit is either to rename that property, which the same convention
asks for anyway, or to argue with the document.

`MAGIC-REPEAT` is deliberately narrower than the document it enforces, and its
green run must not be read as "the magic-value rule is satisfied". It counts
numbers and no strings, because tokens cannot tell a wire key or a fragment of
SQL from a magic value; and it reads one file at a time, so the same value
declared independently by two classes is invisible to it. Both of those stay a
matter for review. A rule may be narrower than its document — it may never be
wider.

That last sentence is a requirement, not an observation, and `MAGIC-REPEAT` has
one place where it is not yet met: a minus is a token of its own, so a symmetric
pair such as `max(-1500, min(1500, $n))` reaches the rule as one value written
twice and is reported. No such site exists in the scanned roots today. It is
written down here rather than left to be rediscovered, because the way out of a
hit is to argue with the document, and an argument needs to know what the rule
actually does.

`EMPTY-STRING-SENTINEL` is narrower than its document in the same way, and on
purpose. It reads the three spellings that mint the literal — `??`, the branch of
a ternary after the colon, and a `match` `default` arm — and stops there. It says
nothing about `=== ''`: those comparisons are how legitimate input is checked, and
a machine ban on them would report the very code the document calls correct. It
also cannot see a bare `return '';`, which mints the same value out of a method
whose caller cannot tell it from data.

Reading a colon costs bookkeeping, because four other constructs spell one: a
named argument, a return type, the alternative syntax, and a `case` label. The
rule counts a colon as a ternary branch only while a `?` of the same bracket depth
is still open, and it tells that `?` from the one of a nullable type by what
stands before it — only a ternary follows something an expression can end with. A
`match` arm is told from a `switch` label the same way: by the double arrow, never
by the arrow alone, which is also how an array element is written.

`PAYLOAD-SENTINEL` overlaps `EMPTY-STRING-SENTINEL` on purpose and is not a
widening of it. It reads two more literals but only eight method bodies, and the
scope is the point: the same `?? 0` is a decision about this object's own state
in a constructor and a decision about somebody else's frame in a payload reader.
Zero was deliberately left out of the empty-string rule for that reason — `?? 0`
occurs 66 times in the framework zone and 89 in the demos and suites, of which
only a quarter sit in a reader, so widening the older rule would have frozen
about 130 records that name no owed work. A line spelled `?? ''` inside a reader
is reported by both rules, which reads as two lines about one site and is the
honest report: both are owed, and both go away with the same edit.

The narrowness is the same kind the rules above have. It cannot see a bare
`return 0;` out of a reader, it does not follow a call into a helper the reader
delegates to, and it judges a method by its name, so a payload read in a method
called something else is invisible to it. That last one is why the name list
carries the helpers of the runtime row and not only the readers themselves: the
`fromRow()` and `applyDiff()` a state declares are `final` and only delegate, so
the reading lives in `hydrateBase()`, `hydrateOwn()` and their diff twins, and a
rule that judged the two entry names alone would see none of it. The rule also
asks nothing about agreement between fields — that check belongs in the
constructor and no token walk can make it.

It needs no zone, unlike the empty-string rule below: eight method bodies across
every root is a small enough subject that its debt fits one baseline and stays
readable as a list of owed work.

`WIRE-KEY-CASE` judges the case of a key and nothing else — not the words, not
whether the two sides agree on them — and it sees only the keys declared in a
form it can recognize. Three blind spots follow from that, and a green run must
not be read as "the convention is kept":

1. **`FIELD_*` constants are outside the rule.** The form carries two meanings at
   once: `FIELD_REQUEST_ID = 'requestId'` names a key of the signal envelope,
   while `FIELD_ENDPOINT_URL = 'endpoint_url'` travels as the *value* of a field
   named `field`. Judging the form would report nineteen legitimate sites across
   the mail, SMS and push channels. The lexical test stays free of exceptions,
   and what it leaves out is a static set of envelope keys rather than anything
   that grows.
2. **A key written as a literal at the place it is used is invisible.** That is
   ownership, not case — [wire-key-ownership.md](wire-key-ownership.md) — and a
   key nobody declared has no declaration to judge.
3. **A `.vue` SFC is not read.** The TypeScript half parses `.ts` files, and no
   SFC declares a row-payload key today; a key that appears in one is a violation
   of ownership before it is ever a question of case.

The PHP half has the opposite gap, and the same sentence governs it: a rule may
never be wider than its document. It reads a camelCase constant name as the
declaration of a field key, which is what that name means in this repository —
but a camelCase constant holding a string that was never a key (`dateFormat =
'Y-m-d H:i:s'`, a URL, a format template) is reported all the same. No such site
exists in the scanned roots today, and the way out of a hit is to argue with the
document, so the argument needs to know what the rule actually does. The rename
that settles it is usually the honest one: a constant that names no wire key is
`UPPER_SNAKE` by the same convention that makes the camelCase name meaningful.

`LINE-LENGTH` reaches one step past what its exemption suggests, and the step is
worth knowing before you argue with a hit. The exemption is syntactic — a heredoc
or nowdoc body — because that syntax is what declares a long line to be content.
An ordinary multi-line quoted string declares nothing of the kind, so a long line
inside one is reported, and the only way out is to edit the string. Where the
whitespace is insignificant that is harmless: the analytics INSERT wraps its
column list and MySQL never notices. Where the newlines are data it is not, and
the cure is to move that text into a heredoc rather than to break it where it
stands.

`MEMBER-INDENT` is narrower than the general PSR-12 indentation rule it points at:
it judges member declaration lines and only them. Method and closure bodies, multiline
expressions, member braces, ordinary comments, and top-level file declarations are
not judged, so mis-indented code inside a method body passes through (an accepted
compromise: adopting php-cs-fixer or enforcing full PSR-12 was rejected on the
2026-09-18 triage to avoid a new dependency and mass reformatting across active
branches). The expected indentation is counted from the line containing the opening
brace rather than four spaces per nesting depth, so anonymous classes passed as call
arguments are judged correctly. Neither `Foo::class` nor named argument `class:`
opens a class body.

`THROWS-PROPAGATION` is the first rule that reads the tree instead of a file, and
its narrowness is declared rather than discovered. It judges a call only when the
target is known without inferring a single type: `$this->m()`, `self::m()`,
`static::m()`, `parent::m()`, `new Bar()` (the contract is the constructor), a
call by class name, and a call through a parameter, a property, a static property
or a `foreach` variable whose type is declared — in the signature, or in a `@var`
when the signature can only say `array`. A chain resolves while every link is
declared and stops at the first one that is not. The declaration is repeated in
the guard's failure message, because a reader of a green run has to know why it
is empty.

An index on such a receiver is one of those calls, not a syntax of its own:
`Hilos::$env[KEY]` is `EnvAccessor::offsetGet()` written shorter, and the guard
asks the whole contract of it. Which of the four `ArrayAccess` methods a pair of
brackets reaches is decided by what surrounds them — `isset()` and `empty()`
reach `offsetExists()`, `unset()` reaches `offsetUnset()`, an assignment into
the index reaches `offsetSet()`, and everything else reads. `empty()` also reads
when the key is there and is counted as a test anyway, on the same principle
that keeps the rule narrower than the code and never wider. An ordinary array
needs no clause to stay silent: a type with the array suffix ends the chain
already, a string offset resolves no `offsetGet()`, and an undeclared receiver
has no type to look one up on.

Five things are outside it on purpose:

1. **A member reached through `__get`.** `Hilos::$db->users` names no declared
   property: the step travels through `__get` and a `@property-read` bridge, and
   no text resolves what the result is. A class-level tag is such a text on the
   class the step is read on and on its ancestors, never on a subclass, and it
   names an instance property. `Hilos::$db` is a declared static property of the
   base type `DbContext` — the `@property-read ChatDbContext $db` a project facade
   writes for its IDE is not read for a static step — while the collection tags
   stand on the project's own context. This
   is the uncomfortable one — `DbContext::__get` is exactly what throws
   `CollectionNotFoundException`, so the most interesting path is the one behind
   the magic — but nothing checks those paths today, so the rule does not make
   the coverage worse; it moves the question out of the dark. What the magic no
   longer hides is the index itself:
   `Hilos::$env[KEY]` and `Hilos::$setting[KEY]` stand on a static property whose
   type is declared, so they resolve like any other receiver.
2. **Vendor and built-in classes.** They are not indexed, so a call into one
   requires nothing. The roots of the PHP exception hierarchy are written into
   the rule as a table instead, which is what lets `@throws Throwable` cover a
   `JsonException` and `@throws RuntimeException` cover a `PDOException`.
3. **A surplus tag.** The rule judges what is missing and never what is extra. A
   `@throws` whose source it cannot see is not a hit, because that source is
   usually the magic above, and reporting it would make the rule wider than its
   document.
4. **Every `throw` but `throw new X`.** A rethrown `throw $e` carries the type of
   whatever was caught, and a `throw SomeException::forErrors(...)` carries the
   type that factory decided to return — following either is type inference under
   another name, and assuming the factory returns its own class would let the rule
   demand a base where the code throws a subclass. Nineteen sites of the factory
   form sit in the indexed roots today and go unreported, ten of them in
   `Cluster`, where a configuration error is raised through a named constructor
   rather than a plain `new`.
5. **The body of a property hook.** A `get`/`set` block is a second shape of
   method body, and the walk does not enter it, so a call made there is checked by
   nobody. The property it belongs to is indexed normally; only the hook's own
   body is dark.

A private helper is walked through rather than asked for a tag, and that is not a
narrowing but the only reading under which this file and
[phpdoc.md](phpdoc.md) agree: that document asks for *no* `@throws` on a private
helper unless it carries a local contract, so a tag there cannot be the answer
and its absence cannot be permission. The exceptions are taken out of the
helper's body and demanded of the public caller, and the report names the whole
chain — `Foo::bar() -> private Foo::helper() -> SocketException` — so the fix is
findable from the line the hit sits on. The walk is memoized and guards against
a cycle.

Coverage by a base class is legal in one direction only, which is the same
asymmetry [phpdoc.md](phpdoc.md) states: `@throws HilosException` answers for a
callee's `ValidationException`, and a narrow tag over a wider thrown type is a
hit, because it says something untrue. A `catch` absorbs on the same terms, and
`catch (Throwable)` absorbs everything; a `catch` body sits outside the `try`
block, so an exception converted and rethrown there is judged as a fresh
`throw new`.

The rule has no zone of its own any more: it judges every production root the
index is built over — `framework/backend`, each demo's backend and `scripts/`.
It was phased in behind one, because judged across every root at once it reported
779 lines in 234 files, which is a mute list and not a list of owed work. The
phases went daemon spine first, then the subsystems of state and data, then the
rest of the framework whole, and `framework/backend/Backup` last, because it
calls through all of them and its own count could only be read once their
contracts were declared. Every one of them is paid, and none owns a baseline
record.

What the demos owe is what the last phase turned up, and it is frozen rather than
paid: 127 lines in 51 files — 107 in 39 for `demo/chat`, 9 in 5 each for
`demo/polls` and `demo/tasks`, 2 in 2 for `demo/cluster`. Freezing
is what stops the debt growing, since a new unpropagated `@throws` fails the
guard on the spot, and the records name HIL-449 as the leaf that pays it. What
is left is the demos' own form, a call inside the showcase that does not
propagate. The other half of the frozen debt was the
implementation-widens-a-declaration form, owed by the framework's own silent
declarations rather than by the demos, and it went out at once when those
declarations were widened rather than file by file. `scripts/` came under the
rule owing nothing at all.

The two markdown rules are narrower than their document as well, and each in a
way worth knowing before you argue with a hit.

`DOC-ROUTE` reads reachability as a direct mention and never expands it: a
wrapper that routes to another wrapper does not inherit that wrapper's files, and
a file reachable only through such a hop is still reported. A route an agent has
to derive is not a route it takes. The rule also judges this catalog alone, and
all of it: a subdirectory of the style catalog is still the style catalog, so a
file put one level down owes a route exactly as a flat one does. That is the
catalog read whole and not a wider scope — the rest of `docs/agents/` has no
owning mechanism to route from, and requiring one there would decide the fate of
documents this check does not own.

`DOC-LINK` judges the file part of a reference and nothing else: `page.md#section`
is checked as `page.md`, and whether the section exists is not asked. It also
stays silent on a bare path inside a document, however broken. A document names
files inside other roots constantly — `pages/keys.ts`, `Bootstrap/daemon.php` —
and reading those as addresses reported 46 legitimate mentions the day it was
tried. In a wrapper the same path *is* an address, because a wrapper exists to be
followed; that difference is the rule, not an oversight.

### Which roots are read, and how each earns its rules

`framework/tests/CodeStyle/ScannedRoots.php` names them, each with the kind that
decides its rule set:

| Root | Kind |
|---|---|
| `framework/backend` | production |
| `scripts` | standalone |
| `framework/tests` | suite |
| `demo/*/backend` | production |
| `demo/*/tests` | suite |

A root is read when it holds PHP this repository runs, or PHP that decides a run —
which is what puts `scripts/` in the list: it carries the runner every Verify
verdict comes out of, and the host-side installers, and it is the one root declared
standalone (below). A demo contributes its two
source directories rather than its whole tree, because `demo/<name>/data/` sits
beside them and holds the root-owned MariaDB files no walk may descend into; a new
demo arrives through the glob with no activation step, and a root that is not there
is skipped in silence, since the framework also ships without the demos. One PHP
file of the repository stays outside every root on purpose:
`framework/Stubs/event.php`, a type stub for an IDE that is never executed.

The kind states a property of the code and not the name of the directory holding it.
A production root is judged by every rule; a suite is judged by every rule but the
six a suite is allowed to break — `ERROR-SUPPRESSION`, `FS-SEAM`, `RANDOM-SOURCE`,
`MAGIC-REPEAT`, `WIRING-REFUSAL-SWALLOWED` and `TRUTH-SOURCE-CLAIM`, the six marked
*Production roots only* in the table above. The last of them is there because a suite
legitimately lays a claim down in a call: an agent a test starts outside
`WorkerManager` gets nothing from the declaration on its class, since the resolver
runs on the worker's start path. A rule
cannot draw that line for itself: it is handed the path relative to the scanned
root, so `framework/tests/Unit/X.php` arrives as `Unit/X.php` and reads exactly like
a backend file.

A standalone root is judged as production is, by every rule, and withholds exactly
one thing: the third sign of `FS-SEAM`, nothing else. The kind names production code
that runs as a PHP process of its own, outside every Hilos manager — it loads no
framework class and installs no warning handler, so the `false` a failing path
primitive returns reaches the code that checks it, and `Hilos\Fs` is not there to
call. `scripts/` is that root. The withholding is the rule's own: `FsSeamRule` is
handed the kind and skips the sign, so the list in `RootKind` of what a suite may
break stays what it is.

Reading the kind off the directory name instead — a root ending in `/backend` is
production, everything else a suite — is what this replaced, and `scripts/` is why:
it ends in no such suffix, so it would have arrived carrying a suite's rule set and
let the suppressed call this catalog's own rule exists for pass unseen.

### Why the rule reads a zone and not the whole tree

`EMPTY-STRING-SENTINEL` is the one rule whose reach depends on the root it is
handed, and the choice is made in `CodeStyleGuardTest`, which is the only place
that knows which root is being scanned.

Inside `framework/backend` it fires only within a path zone — the signal spine
(`Core/Router`, `Core/Page`, `Core/Sync`, `Core/Agent`, `Core/Daemon`,
`Core/Table/DTO`, `Core/Source`), the whole of `Socket` and `Cluster/Peer/DTO`,
the operator and browser layers (`Core/Analytics`, `Core/Browser`, `Core/CLI`,
`Core/Feature`), the application subsystems (`API`, `Auth`, `Backup`,
`Database`, `LLM`, `Log`, `Mail`, `Notification`, `Pages`, `ProtectedMode`,
`Push`, `Runtime`, `Sms`, `Tables`, `Utils`) and `Hilos.php`. The framework is
cleaned one subsystem at a time: turned on across the root at once, its baseline
would become a list of exceptions rather than a list of owed work.

A zone entry matches a whole path segment wherever it sits, not a prefix, which
is what lets `Socket` be taken entire: `Socket/WebSocket/WebSocketFrameDTO.php`
and `Socket/Worker/WorkerDTO.php` sit BESIDE the `DTO` subdirectories the earlier
phases named, and no `Socket/Client`-shaped segment reaches them. The fixture
tree carries a file directly in a segment for exactly this reason — were the
match ever narrowed to a prefix, the fixture report would break before any
production file did.

Every other root — `scripts`, `demo/*/backend`, `framework/tests`, `demo/*/tests` —
is judged entire. A demo is an application on the framework and has no subsystem
outside the mechanism to phase, `scripts/` has no subsystem at all, and a new demo
root arrives through the glob with no activation step, so a segment list would be
forever chasing directories that already exist. The zone is read relative to the
scanned root, so the fixtures repeat the segments of the framework zone to be
judged by the same code, and a fixture root of their own carries what the
whole-root mode has to prove.

Inside the zone, a legal reading of outside input is named in place with a
`// external-boundary: <reason>` marker rather than frozen in the baseline: the
baseline records owed work, and a legal site owes none. The marker covers one
occurrence and its reason is mandatory; see
[method-contracts.md](method-contracts.md) for the convention and its cost.

The zone grows one phase at a time, and each phase pays off the records its
predecessor froze. Turned on everywhere at once, the rule would have reported
several hundred sites in one go: the baseline would then hold more exceptions
than the tree holds clean code, and a list that large is read as a mute list
rather than as owed work — which is the one thing the baseline must never become.

`STYLE-INLINE` reads what the template writes down, and two ways of styling an
element reach the DOM without being written down there. An attribute spread —
Vue's `v-bind="attributes"`, JSX's `{...props}` — can carry a `style` the checker
never sees, and so can a style handed to a third-party component through a prop
of its own. The tree has neither today, and both stay a matter for review. Its
sibling `STYLE-SHEET-HOME` has no blind spot of that kind: it judges a file list,
and a file cannot hide.

`DISABLED-TITLE` reads the same markup and inherits the spread blind spot: an
attribute spread can carry a `title`, a `disabled` or an `aria-label` the checker
never sees. A component that takes a title or an off state under a prop of
another name and puts it on its own button is judged in its own template, not at
the place it is used. And the comparison is textual, so two expressions that
evaluate to the same string but are written differently — a constant on one side,
its text on the other — read as a hit. That is the side to err on: the cure is to
write the name the same way twice.

`MODAL-FOCUS` reads the same markup and inherits that spread blind spot for
`initialFocus` and `data-autofocus`. `'inner'` is trusted: nothing checks that
the component drawing the body carries a mark.

The checker is not a second source of truth. Each rule points back at the
document that owns it, and the failure line carries that path. Change the rule in
the document first; the check follows.

## How to run it

The guard lives in the ordinary framework unit suite, so it runs in the coding
loop and inside `test:framework:all` on Verify without a target of its own:

```bash
composer run test:framework:unit
```

A rule with a TypeScript half rides the frontend unit suite the same way, so it
too needs no target of its own:

```bash
composer run test:framework:frontend:unit
```

A failure lists every unbaselined hit as
`<RULE-ID> <path>:<line> — <what is wrong> (see <doc>)`, whichever of the two
suites produced it.

## Why guard tests and not PHPStan

- **No new dependency.** The rules are lexical, and `token_get_all()` already
  ships with PHP. PHPStan or a fixer would add a toolchain to install, pin, and
  keep green.
- **A precedent already exists.** `framework/tests/Unit/PageSubscriptionContractTest.php`
  guards a contract the same way.
- **It lands in both loops for free.** A PHPUnit test is already run by the
  coding loop and by the full run; a separate tool would need a new composer
  target and a place in every pipeline.

The one check this section used to send away — propagating documented `@throws`
through call chains, PHPStan's `missingCheckedExceptionInThrows` — is
`THROWS-PROPAGATION` above. The premise for sending it away was that it needs
type resolution; what it actually needs is an index of the tree, which the same
`token_get_all()` builds, and the receiver forms an index cannot resolve it
declines out loud instead of inferring. The dependency would have bought less
than it looked: the Hilos magic is where the interesting contracts live, and no
off-the-shelf analyzer reads it without an extension of our own.

## The baseline

Existing debt is recorded in `framework/tests/CodeStyle/baseline.txt`, one record
per rule and file:

```text
RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php 1 # HIL-508
```

- **The anchor is the file and a count, not a line number.** Any edit above a
  violation shifts its line; the count survives.
- **The ticket is mandatory.** A record names the leaf that will remove it, so
  the file reads as a list of owed work instead of a mute list. A record without
  a `HIL-nnn` fails the guard.
- **The baseline can only shrink**, and both halves of the tool hold that line.
  The check fails on more hits than recorded, asks you to lower a count that has
  fewer hits left, and asks you to delete a record with nothing left to cover.
  The update mode below writes the same way round: it lowers counts and drops
  records, and it never raises or adds one.

Regenerate it after paying debt off — the update mode rewrites the file and then
fails on purpose, so the diff is reviewed rather than committed blind. Pressing
the button only ever shrinks the file: a known record is written at the lower of
its count and the tree, a record with nothing left disappears, and a key the
baseline does not already carry is not written at all.

```bash
docker compose -f framework/docker/docker-compose.yml run --rm \
  --user "$(id -u):$(id -g)" -e CODESTYLE_BASELINE_UPDATE=1 \
  hilos-cli-test php vendor/bin/phpunit -c framework/tests/phpunit.xml \
  --testsuite unit --filter CodeStyleGuardTest
```

Pass `--user` as shown: the test container otherwise runs as root and leaves a
root-owned `baseline.txt` behind. The run reports every record it refused to
write — a count that grew, a key that is new — and prints the lines those records
do not cover, so nothing it left out stays invisible. A baseline whose own
records do not parse is left untouched: they have to be readable before the tree
is written into them.

### The frontend has a baseline of its own

The TypeScript rules record their debt in
`framework/frontend/codestyle/baseline.txt`, which is the same file in a second
copy: same record shape, same key, same four verdicts, same shrink-only update,
same `CODESTYLE_BASELINE_UPDATE=1` flag. It is a separate file because the two
suites run in separate processes and each rewrites its own whole; sharing one
would mean whichever suite pressed the button last erased the other's records.

Its guard is `framework/frontend/codestyle/guard.test.ts`, one test fed by every
frontend rule, for the reason the PHP half gives above: a second guard test with
a second baseline erases the first. Each rule keeps its fixture test, which is
the only thing proving the checker still fires.

```bash
docker compose -f framework/docker/docker-compose.frontend.yml run --rm \
  -e CODESTYLE_BASELINE_UPDATE=1 hilos-frontend-cli npm run test
```

Pass no `--user` here, unlike the PHP command above: the workspace's
`node_modules` is installed by a root container, and Vite writes the bundled
config into it before a single test runs — as another user the run dies with
`EACCES` and never reaches the button. The file it rewrites is committed and
therefore always exists, so the root process writes into an inode that already
belongs to you and its ownership does not change.

**A count only grows by hand, written by a person, with a `HIL-nnn` on the
line.** Then the growth shows up in the diff as a decision instead of a side
effect of pressing a button. The same holds for a path the baseline has never
seen: a new record means the debt has spread into code that owed none, and that
is read and dealt with, not frozen. Cascading debt that a leaf induces elsewhere
is work the leaf answers for, which is exactly why the tool refuses to file it
away silently.

## Adding a rule

1. Write or extend the canonical rule in `docs/agents/*` first, and add
   *"Checked automatically: `<RULE-ID>`"* next to it.
2. Implement `Hilos\Tests\CodeStyle\CodeStyleRule` under
   `framework/tests/CodeStyle/Rule/`. `check()` receives the file path **relative
   to the scanned root** and the `token_get_all()` output, and yields one
   `Violation` per occurrence, not per line.
3. Read tokens, not raw text. Both current rules depend on it: a docblock rule
   that reads the file as a string would fire on line comments, and a call rule
   would fire on the same name quoted inside a string literal.
4. Seed `framework/tests/CodeStyle/Fixtures/` with both a case that must be
   caught and a look-alike that must not, and pin the exact report in
   `RuleFixtureTest`. The guard leaves that directory out of its scan — by exact
   path, never by directory name — so this test is the only thing proving the
   rule still fires.
5. Register the rule in `CodeStyleGuardTest`, regenerate the baseline, and give
   every new record an owing leaf.

A rule that judges production code only is listed in `RootKind`, beside the kinds it
is withheld by, and the section on which roots are read says why. It cannot decide
that for itself: `check()` receives the path relative to the
scanned root, so `framework/tests/Unit/X.php` arrives as `Unit/X.php` and is
indistinguishable from a backend file. The kind is declared next to the root.

### A rule with a second half in another language

A rule whose subject crosses the PHP↔TypeScript boundary is written twice, once
per side, under **one** rule id and one owning document — `WIRE-KEY-CASE` is the
first. Each half judges by the convention of its own side, because what declares
a field key is written down differently there, and the two halves print the same
line so a report reads the same whichever produced it.

The TypeScript half lives in `framework/frontend/codestyle/`, a vitest project of
its own like `framework/frontend/scripts/` and, like it, not an npm workspace: it
ships no package and is not type-checked by `npm run check`. Register it by
adding its directory to `projects` in `framework/frontend/vitest.config.ts`; from
then on it runs inside `test:framework:frontend:unit` with no target of its own.
Read sources through the TypeScript compiler API (`ts.createSourceFile`) for the
same reason the PHP half reads tokens — a name quoted inside a string or written
in a comment is not a declaration.

The exception is a rule whose subject is the text itself — every comment, string
and name, the way `SPELLING` judges them. There is nothing for the parser to
narrow, and the compiler reads neither a `.vue` template nor an `.html` file,
both of which carry that text; such a half reads the source text, the way
`SHELL-PARITY` walks the tree. Its PHP half still reads tokens, because tokens
are the only door `CodeStyleRule::check()` has — there they supply the position
of a hit rather than a narrowing of the subject.

The one entry in the table above stays one entry. Two rows would let the halves
drift apart in the register that exists to show they have not.

### A rule that reads the whole tree

A rule whose subject is a contract *between* two files has a third home. The
steps above cannot reach it: the contract a call has to honour is written in the
callee, and the callee almost never sits in the file being read.

1. Build it on `SourceIndex` in `framework/tests/CodeStyle/Throws/`, which
   tokenizes the production roots once and answers where a class stands, whether
   it is abstract, what it extends, uses and implements, which constants it
   declares with which raw value text, what each of its methods declares and with
   what visibility, and where inside a body a call or a `throw` sits. The rule
   file itself goes beside the single-file ones in
   `framework/tests/CodeStyle/Rule/` unless it belongs to the index's own subject,
   as `THROWS-PROPAGATION` does — the index serves more than one rule, and its
   directory is named after the first.
2. Implement `Hilos\Tests\CodeStyle\Throws\CrossFileRule`, not `CodeStyleRule`.
   That interface carries the tokens of one file by construction, and widening it
   would hand eight existing rules an index none of them reads. Do keep yielding
   `Violation` objects, unlike the markdown rules: a cross-file hit is baselined
   like any other, and the failure line has to read the same whichever kind
   produced it.
3. Build the index over **every** production root, and judge every root it spans.
   A demo calls the framework, and an index cut down to part of the tree answers
   "no contract" for half the calls inside it — a silent pass instead of a hit.
4. Seed `framework/tests/CodeStyle/Fixtures/ThrowsTree/` with a toy tree that
   carries its own exception hierarchy, so a fixture question is never answered
   with a production contract, and pin the exact report in
   `ThrowsPropagationFixtureTest`. Seed the look-alikes that must stay silent as
   carefully as the hits. These fixtures need no path exclusion: the index reads
   the production roots, and `framework/tests` is not one of them.
5. Register it in `CodeStyleGuardTest` as a second source feeding the same
   report — **not** in a guard test of its own. The baseline is one file, and
   `CODESTYLE_BASELINE_UPDATE=1` rewrites it whole from the violations of the
   test that regenerates it, so a second test would erase the other's records
   every time either one was run. This is the one point where this kind differs
   from the markdown one, whose rules have no baseline to share.

### A rule that reads markdown

The steps above describe a rule over PHP tokens. A rule that judges the agent
docs has a second home, and shares nothing with the first but the failure-line
format:

1. Put it in `framework/tests/CodeStyle/Markdown/`, next to `MarkdownSources`,
   which lists the scanned files, tells a router from a document, and resolves
   the text of a reference into repository paths.
2. Do not implement `CodeStyleRule`. That interface takes the `token_get_all()`
   output of one PHP file, which markdown has none of. Do not invent a shared
   interface for the markdown rules either: `DOC-ROUTE` judges the tree as a
   whole and `DOC-LINK` a file at a time, and one signature over both would be a
   likeness rather than a contract.
3. Yield finished report lines, not `Violation` objects. A `Violation` exists to
   carry a baseline key, and these rules have no baseline: both halves land
   green, and a debt list here would read as permission to leave the next rule
   file unrouted.
4. Seed `framework/tests/CodeStyle/Fixtures/AgentDocs/` with a toy tree — its own
   catalog, its own wrapper — and pin the exact report in `AgentDocFixtureTest`.
   Seed the look-alikes that must stay silent as carefully as the hits. Those
   fixtures need no path exclusion, unlike the PHP ones: the live scan covers
   `docs/**`, `agents.md`, `CLAUDE.md`, `skills/*` and `demo/*`, and
   `framework/tests` is not among them.
5. Register the rule in `AgentDocGuardTest`, one test method per rule, so two
   unrelated failures do not arrive as one.
