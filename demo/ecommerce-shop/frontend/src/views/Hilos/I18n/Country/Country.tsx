import { HilosI18nCountryPage } from '@hilos/react/admin/i18n'
import { hilosI18nCountryContext } from './hilosI18nCountryContext.js'

/** Bind the framework country card to this demo's singletons. */
export default function Country() {
  return <HilosI18nCountryPage context={hilosI18nCountryContext} />
}
