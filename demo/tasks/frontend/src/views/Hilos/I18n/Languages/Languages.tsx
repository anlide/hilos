import { HilosI18nLanguagesPage } from '@hilos/react'
import { hilosI18nLanguageContext } from '../Language/hilosI18nLanguageContext.js'

/** Bind the framework language list to this demo's singletons. */
export default function Languages() {
  return <HilosI18nLanguagesPage context={hilosI18nLanguageContext} />
}
