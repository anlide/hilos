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
        Hilos Flowers is a demonstration of the Hilos framework — a flower shop
        on a real-time, no-refresh WebSocket application.
      </p>
      <p>
        It is going to be a shop: a storefront of flowers by category, a cart
        that follows you from a guest visit into your account, an order you pay
        for on a stand-in payment page, and its delivery to a parcel locker that
        you watch move step by step — with a search at the top of every page.
      </p>
      <p className="mb-0">
        Today it is the empty shell of that shop: you can create an account and
        sign in, and the home page says who is looking. The storefront and the
        rest arrive one by one.
      </p>
    </HilosAboutPage>
  )
}
