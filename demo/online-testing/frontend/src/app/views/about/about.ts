// The public About page (HilosPages.ABOUT). A framework-declared static page:
// the framework owns the route, the frame, the heading and the support block at
// the end of the text (HilosAboutPage), and this project owns the content. The
// page subscribes like any other but the framework page sends no payload, so
// nothing here depends on the socket.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAboutPage } from '@hilos/angular'

@Component({
  selector: 'app-about',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAboutPage],
  template: `<hilos-about-page>
    <p class="lead">
      Hilos Testing is a demonstration of the Hilos framework — online tests on
      a real-time, no-refresh WebSocket application.
    </p>
    <p>
      It is going to be a testing room: a list of tests you have not taken yet,
      a test answered in one sitting with its score and review right after, the
      tests you have passed, and answers in your own words that an AI checks —
      while administrators write the tests and watch the results come in live.
    </p>
    <p class="mb-0">
      Today it is the empty shell of that room: you can create an account and
      sign in, and the home page says who is looking. The tests and the rest
      arrive one by one.
    </p>
  </hilos-about-page>`,
})
export class About {}
