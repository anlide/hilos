// The hidden value: what a viewer of the admin view mode is sent in place of a
// field the server keeps from them (HIL-1250, PHP
// `Hilos\AdminViewMode\HiddenValue`). On the wire it is the object
// `{"_hidden": true}`, written into the value itself, so it survives the
// entity store's normalization by (type, id). The core reads it once and hands
// it on as a third state of the field — beside the value and null — rather than
// as the empty string or null a plain reader would fold it into: those are what
// made a screen say "No verified email" or "Frozen" about a value it never saw.
//
// One instance stands for every hidden value in the application. A reader hands
// out HIDDEN_VALUE, never the object off the wire, because the shared row-edit
// helper compares a draft with its baseline and its live copy by `Object.is`
// (conflict/rowEdit.ts): two hidden fields are then "unchanged" to it with no
// rule of its own, and an edit window opened on a hidden field is never dirty.
//
// It lives in `state`, beside the field readers, and not in `admin`: it is the
// shape of a value on the wire, as the readers' coercions are, and whoever reads
// a field may meet it. Which screens draw it, and with which word, is the admin
// view mode's (admin/viewMode.ts).

/** The hidden mark: a value the server keeps from a viewer of the view mode. */
export interface HiddenValue {
  readonly _hidden: true
}

/**
 * A field a viewer of the admin view mode may be sent hidden: its value, or
 * {@link HIDDEN_VALUE}.
 */
export type Hideable<T> = T | HiddenValue

/**
 * The one hidden value of the application: every reader hands out this instance,
 * so the row-edit helper's `Object.is` sees two hidden fields as the same.
 */
export const HIDDEN_VALUE: HiddenValue = Object.freeze({ _hidden: true })

/**
 * Whether a value is the hidden mark and nothing else — a plain object with the
 * one key `_hidden` set to `true` (the mirror of PHP `HiddenValue::isMark`).
 *
 * @param value A value read off a frame or a field.
 */
export function isHiddenValue(value: unknown): value is HiddenValue {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false
  }
  const keys = Object.keys(value)

  return (
    keys.length === 1 &&
    keys[0] === '_hidden' &&
    (value as Record<string, unknown>)['_hidden'] === true
  )
}
