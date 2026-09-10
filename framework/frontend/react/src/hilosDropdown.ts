// The option a HilosDropdown renders from. It is view config — the value passed
// to onChange on selection, the visible label, and an optional disabled flag —
// never the selection logic, which the dropdown component owns.
//
// The type lives in the React SDK rather than in @hilos/core: the core carries
// no view concepts, and each view layer names its own option shape.

/** One option of a {@link HilosDropdown}: its value, label, and disabled flag. */
export interface HilosDropdownOption<
  V extends string | number = string | number,
> {
  /** The value passed to onChange when this option is chosen. */
  value: V
  /** The visible option text. */
  label: string
  /** Whether the option is shown but non-selectable (default false). */
  disabled?: boolean
}
