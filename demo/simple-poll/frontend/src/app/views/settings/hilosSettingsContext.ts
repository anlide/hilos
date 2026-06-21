// The poll's HilosSettingsContext: binds the framework Hilos settings page
// (@hilos/angular HilosSettingsPage) to this project's connection, scope stores,
// and action lifecycle. The framework owns the table, the value cell, the row
// view-model, and the add / update / delete round-trips; the project supplies
// only where the data lives — and, on its backend, the actual settings catalog.
import { type HilosSettingsContext } from '@hilos/core'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

/** This project's context for the framework settings page. */
export const hilosSettingsContext: HilosSettingsContext = {
  connection,
  scopes,
  actions,
}
