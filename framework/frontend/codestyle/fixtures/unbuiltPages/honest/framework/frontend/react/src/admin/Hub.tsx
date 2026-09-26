import { HilosPages } from '@hilos/core'
// <HilosAdminPage page={HilosPages.MISSING}>Not a view</HilosAdminPage>
export function Hub() {
  return <HilosAdminPage page={HilosPages.HUB} />
}
