// The injection token carrying which edge of a table the selection column sits
// on. A project provides it once at the app root
// (`{ provide: HILOS_TABLE_SELECTION_EDGE, useValue: 'end' }`); the table injects
// it and falls back to the left edge. It is one choice per installation rather
// than an input per table, because two tables of one product disagreeing about the
// edge is exactly what the rule forbids (table-subscription.md, "Selection and bulk
// actions"); and it lives in the view layer rather than in the core, which has
// nowhere to draw a column and no reader for an edge.
import { InjectionToken } from '@angular/core'

/** Which edge of a table the selection column sits on. */
export type HilosTableSelectionEdge = 'start' | 'end'

/** Provide/inject token for the application's {@link HilosTableSelectionEdge}. */
export const HILOS_TABLE_SELECTION_EDGE =
  new InjectionToken<HilosTableSelectionEdge>('HilosTableSelectionEdge')
