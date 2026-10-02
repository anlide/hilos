// HilosHiddenMark — the one mark of a value the server keeps from a viewer of
// the admin view mode (HIL-1250): a soft pill badge, grey on grey, a struck-out
// eye and the word "Hidden" (HILOS_VIEW_MODE_COPY.hidden). One mark for every
// place a hidden value stands — a table cell, the text of a card, the body of a
// modal, a line inside a cell — so a viewer learns it once; the look is variant
// B, agreed by the owner on 30.09.2026. The eye is decorative and the screen
// reader reads the word, which is also what a string with no markup says in its
// place (hiddenAsWord). A value that may be hidden is drawn through
// HilosHideable, which puts this mark in its place. Bootstrap classes only
// (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HILOS_VIEW_MODE_COPY } from '@hilos/core'

/** The mark of a value the server keeps from a viewer of the admin view mode. */
@Component({
  selector: 'hilos-hidden-mark',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span
    class="badge rounded-pill bg-body-secondary text-body-secondary fw-medium"
    data-id="hilos-hidden"
    ><i class="bi bi-eye-slash me-1" aria-hidden="true"></i
    >{{ copy.hidden }}</span
  >`,
})
export class HilosHiddenMark {
  protected readonly copy = HILOS_VIEW_MODE_COPY
}
