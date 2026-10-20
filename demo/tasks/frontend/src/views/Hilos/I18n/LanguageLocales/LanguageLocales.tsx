import { HilosI18nLanguageLocalesPage } from '@hilos/react/admin/i18n'
import { hilosI18nLanguageContext } from '../Language/hilosI18nLanguageContext.js'

/** Bind the framework language locales page to this demo's singletons. */
export default function LanguageLocales() {
  return <HilosI18nLanguageLocalesPage context={hilosI18nLanguageContext} />
}
