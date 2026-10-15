// The online-testing demo's bindings for the framework languages and countries section.
// The framework owns the tables, the cards, every window and every phrase on
// them; the project supplies only where the data lives and how its actions
// travel — and, on its backend, the section's pages, the library agent and the
// five browser tables.
import type {
  HilosI18nCountryContext,
  HilosI18nLanguageContext,
} from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework language list and language card. */
export const hilosI18nLanguageContext: HilosI18nLanguageContext = {
  connection,
  scopes,
  actions,
}

/** This project's context for the framework country list and country card. */
export const hilosI18nCountryContext: HilosI18nCountryContext = {
  connection,
  scopes,
  actions,
}
