// The public About page (HilosPages.ABOUT). A framework-declared static page:
// the framework owns the route, the frame, the heading and the support block at
// the end of the text (HilosAboutPage), and this project owns the content. The
// page subscribes like any other but the framework page sends no payload, so
// nothing here depends on the socket.
import { HilosAboutPage } from '@hilos/react/base'

export default function About() {
  return (
    <HilosAboutPage>
      <p className="lead">
        Hilos Solar System is a demonstration application built with Hilos.
      </p>
      <p className="mb-0">
        Today it is the empty shell of a solar system model: you can create an
        account and sign in, and it provides the framework's account,
        notification, and administration features in a real-time WebSocket
        application. The model itself arrives later.
      </p>
    </HilosAboutPage>
  )
}
