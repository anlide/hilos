// HilosSettingValueCell — renders one setting's effective value for the settings
// table and the edit dialog: a disabled checkbox for booleans, an italic figure
// for numbers, the text (or an "empty string" chip) otherwise, plus a source
// badge — the referenced key, "default", or "custom" — so the catalog origin is
// visible at a glance. Presentation only; Bootstrap classes (styling-rules.md).
import type { SettingValueSource } from '@hilos/core'

/** Props for {@link HilosSettingValueCell}. */
export interface HilosSettingValueCellProps {
  /** The effective value, serialized; null renders an em dash. */
  value: string | null
  /** The setting's value type (`boolean` | `integer` | `float` | `string`). */
  type: string
  /** Where the effective value comes from (drives the source badge). */
  valueSource: SettingValueSource
  /** The referenced key when the default is a reference, else null. */
  defaultReferenceKey: string | null
}

/**
 * Render one setting value with its catalog-origin badge.
 *
 * @param props The value, its type, its source, and the reference key.
 */
export function HilosSettingValueCell({
  value,
  type,
  valueSource,
  defaultReferenceKey,
}: HilosSettingValueCellProps) {
  const isBoolean = type === 'boolean'
  const isNumber = type === 'integer' || type === 'float'
  const isEmptyString = type === 'string' && (value === null || value === '')
  const display =
    value === null
      ? '—'
      : isBoolean
        ? value === '1'
          ? 'true'
          : 'false'
        : value
  const isReference =
    valueSource === 'reference' && defaultReferenceKey !== null

  return (
    <span
      className="d-inline-flex align-items-center gap-2 mw-100"
      data-id="setting-value"
    >
      {isBoolean ? (
        <input
          type="checkbox"
          className="form-check-input mt-0 flex-shrink-0"
          checked={value === '1'}
          disabled
          readOnly
          tabIndex={-1}
          aria-label={value === '1' ? 'Enabled' : 'Disabled'}
        />
      ) : isEmptyString ? (
        <span
          className="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
          title="Empty string"
        >
          <i className="bi bi-file-earmark" aria-hidden="true" /> empty
        </span>
      ) : (
        <span
          className={`text-truncate${isNumber ? ' fst-italic' : ''}`}
          title={display}
        >
          {display}
        </span>
      )}

      {isReference ? (
        <span
          className="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle d-inline-flex align-items-center gap-1"
          title={`Default from ${defaultReferenceKey}`}
        >
          <i className="bi bi-arrow-down-right" aria-hidden="true" />
          <code className="text-truncate text-info-emphasis">
            {defaultReferenceKey}
          </code>
        </span>
      ) : valueSource === 'default' ? (
        <span
          className="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle flex-shrink-0"
          title="Catalog default"
        >
          default
        </span>
      ) : valueSource === 'override' ? (
        <span
          className="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle flex-shrink-0"
          title="Custom value"
        >
          custom
        </span>
      ) : null}
    </span>
  )
}
