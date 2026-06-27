// The public Terms page (HilosPages.TERMS). A framework-declared static page;
// this project supplies the content. See views/About/About.tsx.
import { HilosStaticPage } from '@hilos/react'

export default function Terms() {
  return (
    <HilosStaticPage title="Terms of Service">
      <p>
        This is a demonstration application provided for evaluation purposes
        only, without warranty of any kind.
      </p>
      <p>
        The todo items you create are processed to show the framework's
        real-time features and may be visible to other participants. Do not
        submit confidential or personal information.
      </p>
      <p className="mb-0">Using this demo implies acceptance of these terms.</p>
    </HilosStaticPage>
  )
}
