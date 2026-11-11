// The SDK owns the public About page frame; this project supplies its content.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAboutPage } from '@hilos/angular/base'

@Component({
  selector: 'app-about',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAboutPage],
  template: `<hilos-about-page>
    <p class="lead">
      Hilos Concert Tickets is a demonstration of the Hilos framework — a
      real-time, no-refresh WebSocket application.
    </p>
    <p class="mb-0">
      This demo currently provides an application shell with accounts, sign-in,
      notifications, and framework administration pages.
    </p>
  </hilos-about-page>`,
})
export class About {}
