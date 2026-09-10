// The option a HilosDropdown renders from. It is view config — the value the
// two-way `value` binding is set to on selection, the visible label, and an
// optional disabled flag — never the selection logic, which the dropdown
// component owns.
//
// The type lives in the Angular SDK rather than in @hilos/core: the core
// carries no view concepts, and each view layer names its own option shape.
//
// The file is named after the type rather than after the component — the way
// hilosToastCorner.ts is — because in this package the component is a .ts file
// too, and `hilosDropdown.ts` beside `HilosDropdown.ts` differs from it only in
// casing, which TypeScript refuses to compile.

/** One option of a {@link HilosDropdown}: its value, label, and disabled flag. */
export interface HilosDropdownOption<
  V extends string | number = string | number,
> {
  /** The value the `value` binding takes when this option is chosen. */
  value: V
  /** The visible option text. */
  label: string
  /** Whether the option is shown but non-selectable (default false). */
  disabled?: boolean
}
