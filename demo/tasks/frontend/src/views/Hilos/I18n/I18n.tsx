import { HilosI18nPage } from '@hilos/react/admin/i18n'
import { scopes } from '../../../bootstrap/session.js'

/** Stable for the overview selector: a new object each render would rebuild it. */
const context = { scopes }

/** Bind the framework internationalization overview to this demo's scopes. */
export default function I18n() {
  return <HilosI18nPage context={context} />
}
