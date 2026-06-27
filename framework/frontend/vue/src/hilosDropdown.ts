// The option a HilosDropdown renders from. It is view config — the value emitted
// on selection, the visible label, and an optional disabled flag — never the
// selection logic, which the dropdown component owns.

/** One option of a {@link HilosDropdown}: its value, label, and disabled flag. */
export interface HilosDropdownOption<
  V extends string | number = string | number,
> {
  /** The value emitted through v-model when this option is chosen. */
  value: V
  /** The visible option text. */
  label: string
  /** Whether the option is shown but non-selectable (default false). */
  disabled?: boolean
}
