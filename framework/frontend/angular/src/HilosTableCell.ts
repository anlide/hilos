// HilosTableCell — the mark a page puts on an ng-template to hand a declared
// HilosViewportTable the content of one column: `<ng-template hilosTableCell="name"
// let-row let-rowKey="rowKey">{{ row.name }}</ng-template>`. The page writes the
// content only; the table writes the `<td>` around it in a row, and the place it takes
// in a card on a narrow screen, both out of this one template. A static name
// (`#row`) could not carry it: the column a template fills is known only at run time,
// so the table collects every marked template and reads the key off each.
import { Directive, TemplateRef, inject, input } from '@angular/core'

/** The context a `hilosTableCell` template receives. */
export interface HilosTableCellContext<R> {
  /** The resolved row view-model (the template's implicit `let-row`). */
  $implicit: R
  /** The row's stable key. */
  rowKey: string
}

/** The content of one declared column's cell, marked with the key of that column. */
@Directive({ selector: 'ng-template[hilosTableCell]' })
export class HilosTableCell {
  /** The key of the column whose cell this template fills; the `hilosTableCell` attribute value. */
  readonly hilosTableCell = input.required<string>()
  /** The marked template itself, stamped into the row's cell and into the card. */
  readonly template =
    inject<TemplateRef<HilosTableCellContext<unknown>>>(TemplateRef)
}
