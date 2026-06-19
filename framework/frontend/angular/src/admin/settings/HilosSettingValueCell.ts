// HilosSettingValueCell — renders one setting's effective value for the settings
// table and the edit dialog: a disabled checkbox for booleans, an italic figure
// for numbers, the text (or an "empty string" chip) otherwise, plus a source
// badge — the referenced key, "default", or "custom" — so the catalog origin is
// visible at a glance. Presentation only; Bootstrap classes (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import type { SettingValueSource } from '@hilos/core'

/** Render one setting value with its catalog-origin badge. */
@Component({
  selector: 'hilos-setting-value-cell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span
      class="d-inline-flex align-items-center gap-2 mw-100"
      data-id="setting-value"
    >
      @if (isBoolean()) {
        <input
          type="checkbox"
          class="form-check-input mt-0 flex-shrink-0"
          [checked]="value() === '1'"
          disabled
          tabindex="-1"
          [attr.aria-label]="value() === '1' ? 'Enabled' : 'Disabled'"
        />
      } @else if (isEmptyString()) {
        <span
          class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
          title="Empty string"
        >
          <i class="bi bi-file-earmark" aria-hidden="true"></i> empty
        </span>
      } @else {
        <span
          class="text-truncate"
          [class.fst-italic]="isNumber()"
          [title]="display()"
          >{{ display() }}</span
        >
      }

      @if (isReference()) {
        <span
          class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle d-inline-flex align-items-center gap-1"
          [title]="'Default from ' + defaultReferenceKey()"
        >
          <i class="bi bi-arrow-down-right" aria-hidden="true"></i>
          <code class="text-truncate text-info-emphasis">{{
            defaultReferenceKey()
          }}</code>
        </span>
      } @else if (valueSource() === 'default') {
        <span
          class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
          title="Catalog default"
          >default</span
        >
      } @else if (valueSource() === 'override') {
        <span
          class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle flex-shrink-0"
          title="Custom value"
          >custom</span
        >
      }
    </span>
  `,
})
export class HilosSettingValueCell {
  /** The effective value, serialized; null renders an em dash. */
  readonly value = input.required<string | null>()
  /** The setting's value type (`boolean` | `integer` | `float` | `string`). */
  readonly type = input.required<string>()
  /** Where the effective value comes from (drives the source badge). */
  readonly valueSource = input.required<SettingValueSource>()
  /** The referenced key when the default is a reference, else null. */
  readonly defaultReferenceKey = input.required<string | null>()

  protected readonly isBoolean = computed(() => this.type() === 'boolean')
  protected readonly isNumber = computed(
    () => this.type() === 'integer' || this.type() === 'float',
  )
  protected readonly isEmptyString = computed(
    () =>
      this.type() === 'string' &&
      (this.value() === null || this.value() === ''),
  )
  protected readonly display = computed(() => {
    const value = this.value()
    if (value === null) {
      return '—'
    }
    if (this.isBoolean()) {
      return value === '1' ? 'true' : 'false'
    }

    return value
  })
  protected readonly isReference = computed(
    () =>
      this.valueSource() === 'reference' && this.defaultReferenceKey() !== null,
  )
}
