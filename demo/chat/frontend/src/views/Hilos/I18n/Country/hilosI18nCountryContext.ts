import { type HilosI18nCountryContext } from '@hilos/core'
import { actions, connection } from '../../../../bootstrap/connection.js'
import { scopes } from '../../../../bootstrap/session.js'

/** The chat demo's binding for the framework country card. */
export const hilosI18nCountryContext: HilosI18nCountryContext = {
  connection,
  scopes,
  actions,
}
