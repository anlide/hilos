import { HilosI18nLanguageNamesPage } from '@hilos/react'
import { hilosI18nLanguageContext } from '../Language/hilosI18nLanguageContext.js'

/** Bind the framework language names page to this demo's singletons. */
export default function LanguageNames() {
  return <HilosI18nLanguageNamesPage context={hilosI18nLanguageContext} />
}
