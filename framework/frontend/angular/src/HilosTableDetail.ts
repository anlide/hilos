// HilosTableDetail — the mark a page puts on an ng-template to hand a
// HilosViewportTable the value of one field of the panel a row expands into:
// `<ng-template hilosTableDetail="lastError" let-row let-rowKey="rowKey">{{
// row.lastError }}</ng-template>`. The page writes the value only; the table writes
// the label and the room around it, under the row and inside the card alike. Apart
// from hilosTableCell because a field of the panel is not a cell: its column left the
// row, and the panel works on a table drawing its frame from inputs as well, where no
// cell is addressed by column at all. The context is the cell's own — a field is
// handed exactly what a cell is.
import { Directive, TemplateRef, inject, input } from '@angular/core'

import type { HilosTableCellContext } from './HilosTableCell.js'

/** The value of one field of a row's panel, marked with the key of its column. */
@Directive({ selector: 'ng-template[hilosTableDetail]' })
export class HilosTableDetail {
  /** The key of the column whose field this template fills; the `hilosTableDetail` attribute value. */
  readonly hilosTableDetail = input.required<string>()
  /** The marked template itself, stamped into the panel under the row and into the card's. */
  readonly template =
    inject<TemplateRef<HilosTableCellContext<unknown>>>(TemplateRef)
}
