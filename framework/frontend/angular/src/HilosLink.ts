// HilosLink — the in-place navigation directive on an anchor. It binds the
// anchor's href, so the link keeps every native affordance (open-in-new-tab,
// copy address, keyboard focus) and degrades to a hard navigation when no
// router is provided. Only the plain primary click is intercepted and handed to
// the core navigator (HilosRouter), turning it into a no-refresh page
// transition that leaves the WebSocket connection untouched.
import { Directive, HostListener, inject, input } from '@angular/core'

import { HILOS_ROUTER } from './hilosRouterToken.js'

/** An anchor that navigates in place through the provided HilosRouter. */
@Directive({
  selector: 'a[hilosLink]',
  host: { '[attr.href]': 'hilosLink()' },
})
export class HilosLink {
  /** The target location pathname; the `hilosLink` attribute value. */
  readonly hilosLink = input.required<string>()

  private readonly router = inject(HILOS_ROUTER, { optional: true })

  /**
   * Intercept the plain primary click and navigate in place.
   *
   * @param event The anchor click event.
   */
  @HostListener('click', ['$event'])
  onClick(event: MouseEvent): void {
    // Leave modified clicks and non-primary buttons to the browser (new tab,
    // new window); without a router the anchor stays a plain hard link.
    if (
      this.router === null ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return
    }
    event.preventDefault()
    this.router.navigate(this.hilosLink())
  }
}
