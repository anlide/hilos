import { type HilosI18nCountryContext } from '@hilos/core'
import { connection } from '../../../../bootstrap/connection.js'
import { scopes } from '../../../../bootstrap/session.js'

/** The tracker demo's binding for the framework country card. */
export const hilosI18nCountryContext: HilosI18nCountryContext = {
  connection,
  scopes,
}
