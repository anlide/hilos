import { HilosI18nCountriesPage } from '@hilos/react/admin/i18n'
import { hilosI18nCountryContext } from '../Country/hilosI18nCountryContext.js'

/** Bind the framework country list to this demo's singletons. */
export default function Countries() {
  return <HilosI18nCountriesPage context={hilosI18nCountryContext} />
}
