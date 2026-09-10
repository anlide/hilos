// The public About page (HilosPages.ABOUT). A framework-declared static page:
// the framework owns the route, the frame, the heading and the support block at
// the end of the text (HilosAboutPage), and this project owns the content. The
// page subscribes like any other but the framework page sends no payload, so
// nothing here depends on the socket.
import { HilosAboutPage } from '@hilos/react'

export default function About() {
  return (
    <HilosAboutPage>
      <p className="lead">
        Hilos Tasks is a demonstration of the Hilos framework — a real-time,
        no-refresh WebSocket application whose React view layer is driven by a
        framework-agnostic core.
      </p>
      <p>
        It keeps a shared task list in sync across clients over the Hilos signal
        protocol, using the same SDK that powers the Vue and Angular demos.
      </p>
      <p className="mb-0">
        The page you are reading is a framework-declared static page: the
        framework owns its route and layout, while this project supplies the
        text.
      </p>
    </HilosAboutPage>
  )
}
