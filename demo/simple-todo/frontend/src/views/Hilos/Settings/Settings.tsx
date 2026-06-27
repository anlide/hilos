// The Hilos settings admin page (HilosPages.SETTINGS). A framework-owned admin
// view: the framework owns the table, the row view-model, and the
// add / update / delete lifecycle (@hilos/react HilosSettingsPage); this project
// binds only its scope stores and action lifecycle through hilosSettingsContext,
// and declares the catalog on its backend.
import { HilosSettingsPage } from '@hilos/react'

import { hilosSettingsContext } from './hilosSettingsContext'

export default function Settings() {
  return <HilosSettingsPage context={hilosSettingsContext} />
}
