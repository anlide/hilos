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
        Nothing it shows is for sale: the flowers, the prices and the orders it
        will show are made up to show the framework's real-time features, no
        payment is ever taken, and nothing is ever delivered.
      </p>
      <p className="mb-0">Using this demo implies acceptance of these terms.</p>
    </HilosStaticPage>
  )
}
