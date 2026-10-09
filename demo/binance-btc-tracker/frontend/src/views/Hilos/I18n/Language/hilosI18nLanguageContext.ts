import { type HilosI18nLanguageContext } from '@hilos/core'
import { actions, connection } from '../../../../bootstrap/connection.js'
import { scopes } from '../../../../bootstrap/session.js'

/** The tracker demo's binding for the framework language card. */
export const hilosI18nLanguageContext: HilosI18nLanguageContext = {
  connection,
  scopes,
  actions,
}
