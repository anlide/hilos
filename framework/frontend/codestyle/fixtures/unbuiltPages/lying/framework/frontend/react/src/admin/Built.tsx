import { HilosPages } from '@hilos/core'
// <HilosAdminPage page={HilosPages.MISSING}>Not a view</HilosAdminPage>
export function Built() {
  return (
    <HilosAdminPage page={HilosPages.BUILT}>
      <p>Built</p>
    </HilosAdminPage>
  )
}
