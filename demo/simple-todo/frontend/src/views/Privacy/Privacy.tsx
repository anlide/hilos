// The public Privacy page (HilosPages.PRIVACY). A framework-declared static
// page; this project supplies the content. See views/About/About.tsx.
import { HilosStaticPage } from '@hilos/react'

export default function Privacy() {
  return (
    <HilosStaticPage title="Privacy">
      <p>
        This demo stores only the data needed to show its real-time features:
        your chosen display name and the todo items you create.
      </p>
      <p className="mb-0">
        No analytics or third-party trackers are used, and demo data may be
        reset at any time.
      </p>
    </HilosStaticPage>
  )
}
