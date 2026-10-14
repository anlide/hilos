import { HilosI18nLanguagePage } from '@hilos/react'
import { hilosI18nLanguageContext } from './hilosI18nLanguageContext.js'

/** Bind the framework language card to this demo's singletons. */
export default function Language() {
  return <HilosI18nLanguagePage context={hilosI18nLanguageContext} />
}
