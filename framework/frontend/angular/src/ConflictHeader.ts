// ConflictHeader — a modal header that flags an unresolved 3-way-merge conflict.
// The selector is an attribute on a native h5, so the host IS the title element:
// drop it in a modal header as `<h5 hilosConflictHeader [title]="…">`. It shows
// the title and, once a field has diverged on both the user's and the server's
// side (the core threeWayMerge `conflict` result), a badge so the user knows a
// resolution is pending before Save re-enables. Bootstrap classes only.
import { ChangeDetectionStrategy, Component, input } from '@angular/core'

/** A modal title that badges an unresolved conflict. */
@Component({
  selector: 'h5[hilosConflictHeader]',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'modal-title d-inline-flex align-items-center gap-2 mb-0' },
  template: `
    <span>{{ title() }}</span>
    @if (conflict()) {
      <span class="badge text-bg-danger" data-id="conflict-badge"
        >conflict</span
      >
    }
  `,
})
export class ConflictHeader {
  /** The dialog title. */
  readonly title = input.required<string>()
  /** Whether an unresolved conflict is present. */
  readonly conflict = input(false)
}
