import { HilosI18nCountryNamesPage } from '@hilos/react/admin/i18n'
import { hilosI18nCountryContext } from '../Country/hilosI18nCountryContext.js'

/** Bind the framework country names page to this demo's singletons. */
export default function CountryNames() {
  return <HilosI18nCountryNamesPage context={hilosI18nCountryContext} />
}
