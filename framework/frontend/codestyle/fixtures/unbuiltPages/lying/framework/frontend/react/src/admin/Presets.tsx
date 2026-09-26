import { HilosPages } from '@hilos/core'
// <HilosAdminPage page={HilosPages.MISSING}>Not a view</HilosAdminPage>
export function Presets() {
  return <HilosSettingPresetsPage page={HilosPages.PRESETS} />
}
