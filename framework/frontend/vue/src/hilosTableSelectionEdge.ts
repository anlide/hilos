// The injection key carrying which edge of a table the selection column sits on.
// A project provides it once at the app root
// (`app.provide(hilosTableSelectionEdgeKey, 'end')`); the table injects it and
// falls back to the left edge. It is one choice per installation rather than a
// prop per table, because two tables of one product disagreeing about the edge is
// exactly what the rule forbids (table-subscription.md, "Selection and bulk
// actions"); and it lives in the view layer rather than in the core, which has
// nowhere to draw a column and no reader for an edge.
import type { InjectionKey } from 'vue'

/** Which edge of a table the selection column sits on. */
export type HilosTableSelectionEdge = 'start' | 'end'

/** Provide/inject key for the application's {@link HilosTableSelectionEdge}. */
export const hilosTableSelectionEdgeKey: InjectionKey<HilosTableSelectionEdge> =
  Symbol('hilosTableSelectionEdge')
