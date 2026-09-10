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
      Hilos Polls is a demonstration of the Hilos framework — a real-time,
      no-refresh WebSocket application whose Angular view layer is driven by a
      framework-agnostic core.
    </p>
    <p>
      It tallies live poll votes across clients over the Hilos signal protocol,
      using the same SDK that powers the Vue and React demos.
    </p>
    <p class="mb-0">
      The page you are reading is a framework-declared static page: the framework
      owns its route and layout, while this project supplies the text.
    </p>
  </hilos-about-page>`,
})
export class About {}
