// The Hilos Appearance admin page (HilosPages.APPEARANCE). A framework-owned admin
// view: the framework owns the table of the two theme settings, its row
// view-model, both edit windows and their writes (@hilos/react
// HilosAppearancePage); this project binds only its connection, scope stores and
// action lifecycle through hilosAppearanceContext.
import { HilosAppearancePage } from '@hilos/react/admin/appearance'

import { hilosAppearanceContext } from './hilosAppearanceContext.js'

export default function Appearance() {
  return <HilosAppearancePage context={hilosAppearanceContext} />
}
