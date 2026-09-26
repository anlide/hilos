import { HilosPages } from '@hilos/core'
// <HilosAdminPage page={HilosPages.MISSING}>Not a view</HilosAdminPage>
export function Empty() {
  return <HilosAdminPage page={HilosPages.EMPTY} />
}
