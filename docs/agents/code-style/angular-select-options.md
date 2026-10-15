# Angular Select Options

Read this before writing a native Angular `select` or `option`, in an inline
component template or an external HTML template.

## Core Rule

Put the value and the selected predicate on each dynamic `option`: `[value]`
with `[selected]`. Leave `[value]` off the `select`. Keep the ordinary `(change)`
handler reading `HTMLSelectElement.value`.

Angular can write a select's value before its options receive their values, or
before a late catalog creates them. An unchanged select binding is not written
again when those options arrive. The state then names one choice while the DOM
shows another. An option's own selected predicate is applied when that option is
created and when the choice changes.

## Preferred Shape

```html
<select (change)="onStream($event)">
  <option value="" [selected]="(selection().stream ?? '') === ''">
    Choose a stream
  </option>
  @for (stream of streams(); track stream.key) {
    <option [value]="stream.key" [selected]="stream.key === selection().stream">
      {{ stream.key }}
    </option>
  }
</select>
```

A controlled empty choice needs its own predicate. Compare numeric choices in
their state type; the DOM still exposes a string to the existing change handler:

```html
<select (change)="onSource($event)">
  <option [value]="live" [selected]="selection().source === live">Live journal</option>
  @for (batch of batches(); track batch) {
    <option [value]="batch" [selected]="selection().source === batch">
      {{ batchLabel(batch) }}
    </option>
  }
</select>
```

Do not bind the same choice twice, even when the options already have predicates:

```html
<!-- Wrong: the select binding can run before the option values exist. -->
<select [value]="choice()">
  @for (value of values(); track value) {
    <option [value]="value">{{ value }}</option>
  }
</select>
```

## Static Choices

A sole immutable option may keep static `selected`, as may an empty disabled
prompt created only while the state is empty (the i18n locale window's shape).
Static `selected` does not replace `[selected]` on an option with a bound value.
Static options without a bound value, ordinary inputs and custom components are
outside the machine rule.

## Validation and Machine Boundary

Checked automatically: `ANGULAR-SELECT-OPTION`.

The guard reports exactly two shapes: a value binding on a native `select`, and
a native `option` with a value binding but no selected binding. `[value]` and
`bind-value` are equivalent; so are `[selected]` and `bind-selected`. The report
names the offending value attribute's line. It checks syntax, not whether the
selected predicate chooses the right value.

The scan reads `framework/frontend/angular/src` and `demo/*/frontend/src` when
that demo's frontend package declares `@angular/core` or `@hilos/angular` in
dependencies or devDependencies. It reads external `.html` files and literal
`template` properties of `@Component({...})` decorators in `.ts` files. Vue and
React roots, dependencies, build output and test fixtures are excluded.

The shared scanner handles multiline tags, quoted `>` and HTML comments, and
reads tags inside `@if` / `@for` without interpreting the control flow. The
TypeScript compiler API locates decorators; unrelated strings and comments are
not templates. Only a direct `@Component` name and a literal object with a
literal string or non-interpolated template string are read. Aliased or
namespaced decorators, computed metadata, concatenated/imported templates,
JavaScript template substitutions and escaped/generated markup are not
resolved. Attribute interpolation, `[attr.value]`, `ngValue`, `ngModel`, spread
attributes and runtime DOM writes are outside these two binding checks. A green
guard does not validate those forms or replace review.

Use TestBed assertions on real `HTMLSelectElement.value` and selected options
to prove the predicate, including nondefault choices and options arriving after
the first render with an unchanged selected key. Run
`composer run test:framework:frontend` for Angular template compilation, those
DOM tests and all frontend guards. The guard shares their existing baseline;
this rule introduces no baseline debt.
