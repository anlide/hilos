// HilosToastHost — the framework toast stack: transient notices in a fixed
// bottom-end corner, newest at the bottom, each self-expiring on the store's
// timer. It renders the shared core store (hilosToasts), so anything in the SDK
// or in a project can report an outcome without threading a store through inputs —
// the shell mounts this once (HilosLayout) and every page is covered.
//
// The host owns no policy: it draws the cards, reports raw measurements (the
// window height and each card's occupied height) and reports the holds on the
// countdown. How long a notice lives, how much of the corner it may fill and
// what happens to the ones that do not fit is the store's business, in one copy
// for the three SDKs (core/src/state/toasts.ts).
//
// Markup is the Bootstrap toast component driven declaratively — `.toast.show`
// rendered by the store rather than Bootstrap's JS Toast (the SDK ships
// Bootstrap's CSS, not its JS; HilosModal does the same). The card keeps the
// stock surface — the width, the translucent body background, the border, the
// shadow and the z-index that puts the stack over a modal — and names its
// severity with a colored rail and an icon instead of a solid `text-bg-*` fill:
// a fill carries neither a readable link nor a long line, and in the dark theme
// a thin border in its place all but disappears (mockups/components/toast).
// Bootstrap classes only; what stock utilities cannot express lives in
// hilos-styles.scss, which an application lists in angular.json because
// ng-packagr ships no transitive CSS (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  effect,
  input,
  signal,
  viewChild,
  viewChildren,
} from '@angular/core'
import { hilosToasts, subscribeSignal } from '@hilos/core'
import type {
  HilosToast,
  HilosToastHoldReason,
  HilosToastOverflow,
  HilosToastSeverity,
  HilosToastStore,
  HilosToastViewer,
} from '@hilos/core'

import { HilosLink } from './HilosLink.js'
import type { HilosToastCorner } from './hilosToastCorner.js'

// What names a severity on the light card: the color of the rail down its left
// edge, the color of its icon, and the icon itself. One table of the three
// rather than three lookups or class strings glued together in the template —
// glued classes read badly and cannot be grepped (CONN_VISUAL in HilosLayout is
// the same shape).
type ToastVisual = { rail: string; accent: string; icon: string }
const TOAST_VISUAL: Record<HilosToastSeverity, ToastVisual> = {
  error: {
    rail: 'border-danger',
    accent: 'text-danger',
    icon: 'bi-x-circle-fill',
  },
  success: {
    rail: 'border-success',
    accent: 'text-success',
    icon: 'bi-check-circle-fill',
  },
  warning: {
    rail: 'border-warning',
    accent: 'text-warning',
    icon: 'bi-exclamation-triangle-fill',
  },
  info: {
    rail: 'border-primary',
    accent: 'text-primary',
    icon: 'bi-info-circle-fill',
  },
}

// The measuring layer: a card the store has not measured yet is still rendered —
// it has to be, to be measured — but it is taken out of the flow and out of
// sight until the store admits it. Not `d-none`, which reports zero height and
// breaks the measurement, and not `invisible`, which drops the card out of the
// accessibility tree and silences the announcement; `opacity-0` does neither.
// `pe-none` so a card nobody can see cannot take the cursor from the ones that
// are visible and hand the store a hold on their countdown.
const MEASURING_LAYER = 'position-absolute opacity-0 pe-none'

/**
 * Whether a focus event only moved focus inside the stack.
 *
 * The cursor and keyboard focus are two independent holds on the countdown: the
 * host only reports them, the store counts them. Tabbing from one close button to
 * the next is neither an arrival nor a leave, and `relatedTarget` is what tells
 * those apart; without the check the holds would never balance out.
 *
 * @param event The bubbled focusin / focusout.
 */
function movesWithin(event: FocusEvent): boolean {
  const container = event.currentTarget as HTMLElement

  return container.contains(event.relatedTarget as Node | null)
}

/**
 * How much of the stack one card takes: its own box plus the spacing under it.
 *
 * The store adds these up against a third of the window, so what it is given has
 * to be the room the card occupies, not the room it paints in.
 *
 * @param element The rendered card.
 */
function occupiedHeight(element: HTMLElement): number {
  const spacing = Number.parseFloat(getComputedStyle(element).marginBottom)

  return (
    element.getBoundingClientRect().height +
    (Number.isNaN(spacing) ? 0 : spacing)
  )
}

/** The application's transient notice stack. */
@Component({
  selector: 'hilos-toast-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <!-- The two live regions, declared in advance and on the stack rather than
    on the card that just appeared: a role attached to a freshly inserted node
    leaves part of the screen readers silent. Two of them, because only a failure
    is allowed to interrupt what the listener is hearing. They sit OUTSIDE the
    stack container: inside, they would double every visible line for a search by
    text, which is how the demo suites find a notice. -->
    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      data-id="hilos-toast-live-assertive"
    >
      @for (toast of spokenErrors(); track toast.id) {
        <div>{{ spoken(toast) }}</div>
      }
    </div>
    <div
      class="visually-hidden"
      role="status"
      aria-live="polite"
      data-id="hilos-toast-live-polite"
    >
      @for (toast of spokenRest(); track toast.id) {
        <div>{{ spoken(toast) }}</div>
      }
    </div>
    <div
      #stack
      class="toast-container position-fixed p-3"
      [class]="cornerClasses()"
      data-id="hilos-toasts"
      (mouseover)="setHold('cursor', true)"
      (mouseleave)="setHold('cursor', false)"
      (focusin)="holdOnFocus($event)"
      (focusout)="releaseOnBlur($event)"
    >
      @for (toast of toasts(); track toast.id) {
        <div
          #card
          class="toast fade show overflow-hidden"
          [class]="measuring(toast)"
          [attr.data-id]="'hilos-toast-' + toast.severity"
        >
          <!-- The card's one positioned box: the rail, the icon and the text sit
          in it, and it is what the message's stretched link covers, so the whole
          card leads where the notice points while the close button stays
          clickable over it (.z-2). The click that closes a navigated card is
          taken here rather than on the link: the order between a directive's
          host listener and a (click) on the same element is not fixed by
          contract, while bubbling to the parent is ordered by the platform. -->
          <div
            class="border-start border-4 d-flex align-items-start gap-2 p-2 position-relative"
            [class]="visual(toast.severity).rail"
            (click)="dismissIfNavigated($event, toast.id)"
          >
            <i [class]="iconClass(toast.severity)" aria-hidden="true"></i>
            <div class="flex-grow-1 min-w-0">
              @if (toast.source !== null) {
                <div
                  class="hilos-toast-source text-body-secondary mb-1"
                  data-id="hilos-toast-source"
                >
                  {{ toast.source }}
                </div>
              }
              <div class="d-flex align-items-start gap-2">
                <div class="hilos-toast-clamp flex-grow-1 min-w-0">
                  @if (toast.destination !== null) {
                    <a
                      [hilosLink]="toast.destination"
                      class="stretched-link link-body-emphasis text-decoration-none"
                      >{{ toast.message }}</a
                    >
                  } @else {
                    {{ toast.message }}
                  }
                </div>
                @if (toast.repeats > 1) {
                  <span
                    class="badge text-bg-light border"
                    data-id="hilos-toast-repeats"
                    >×{{ toast.repeats }}</span
                  >
                }
              </div>
            </div>
            <button
              type="button"
              class="btn-close position-relative z-2"
              aria-label="Close"
              data-id="hilos-toast-close"
              (click)="dismiss(toast.id)"
            ></button>
          </div>
          <!-- The life bar, and only where a countdown actually runs: an error
          never expires, and a card the host has not reported yet has no
          countdown started. The single-pass @for over the repeat count is what
          React writes as a key: a merge gives the notice its full time back, and
          without a fresh node the animation would finish the old round and show
          a time that is not the one running. -->
          @if (toast.measured && toast.severity !== 'error') {
            @for (round of [toast.repeats]; track round) {
              <div
                class="hilos-toast-life"
                [class]="visual(toast.severity).accent"
                [class.hilos-toast-life-paused]="frozen()"
                data-id="hilos-toast-life"
              ></div>
            }
          }
        </div>
      }
      <!-- The service line: how many errors are still queued and how many
      notices were dropped, under the newest card. It carries no #card, so the
      measuring effect never pairs it with a toast and it never reaches
      reportHeight() — a line that says what did not fit must not push out what
      did. It carries pe-auto because .toast-container turns pointer events off
      and only .toast turns them back on: without it the line would be the one
      part of the stack that the cursor resting on it does not count as
      reading. -->
      @if (overflow().waiting > 0 || overflow().missed > 0) {
        <div
          class="bg-body border rounded-3 shadow-sm px-2 py-1 small text-body-secondary pe-auto"
          data-id="hilos-toast-overflow"
        >
          <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i
          >{{ overflowLine() }}
        </div>
      }
    </div>
  `,
})
export class HilosToastHost {
  /** The stack to render; defaults to the application-wide store. */
  readonly store = input<HilosToastStore>(hilosToasts)

  /** Which corner the stack sits in; defaults to the bottom end. */
  readonly corner = input<HilosToastCorner>('bottom-end')

  protected readonly toasts = signal<readonly HilosToast[]>([])

  protected readonly overflow = signal<HilosToastOverflow>({
    waiting: 0,
    missed: 0,
  })

  // Where the stack sits: the horizontal edge is a stock Bootstrap utility, the
  // vertical one is not, because the narrow screen overrides it and the stock
  // .bottom-0 carries !important that a media query cannot outrank.
  protected readonly cornerClasses = computed(() => {
    const corner = this.corner()
    const horizontal = corner.endsWith('-end') ? 'end-0' : 'start-0'
    const vertical = corner.startsWith('bottom-')
      ? 'hilos-toast-stack-bottom'
      : 'hilos-toast-stack-top'

    return `${horizontal} ${vertical}`
  })

  // What the live regions say. A notice reaches them only once it is measured:
  // until then the store may still take it into the queue or into the missed
  // count, and announcing a card that will not appear promises something that
  // can be neither read nor dismissed. A repeat does not change the text, so
  // twenty identical failures are read once — the merge, carried over to
  // hearing.
  private readonly announced = computed(() =>
    this.toasts().filter((toast) => toast.measured),
  )

  protected readonly spokenErrors = computed(() =>
    this.announced().filter((toast) => toast.severity === 'error'),
  )

  protected readonly spokenRest = computed(() =>
    this.announced().filter((toast) => toast.severity !== 'error'),
  )

  // What the service line under the stack says: the errors still queued and the
  // notices that were dropped. A piece appears only when it has something to
  // report, and the joined pieces are the canon's wording rather than this
  // host's (docs/agents/frontend/toasts.md). The store zeroes both numbers
  // itself once the stack empties, so the line goes away without the host doing
  // anything.
  protected readonly overflowLine = computed(() => {
    const overflow = this.overflow()
    const pieces: string[] = []

    if (overflow.waiting > 0) {
      pieces.push(`${overflow.waiting} more waiting`)
    }
    if (overflow.missed > 0) {
      pieces.push(`${overflow.missed} missed`)
    }

    return pieces.join(' · ')
  })

  // The rendered cards, in the order the `@for` laid them out — which is the
  // order of `toasts()`, because they come from the same loop. Anything the
  // stack grows around the cards carries no `#card`, so it never shifts the
  // pairing — the container included, which is why it is `#stack`.
  private readonly cards = viewChildren<ElementRef<HTMLElement>>('card')

  private readonly stack = viewChild<ElementRef<HTMLElement>>('stack')

  private viewer: HilosToastViewer | null = null

  // The three holds this host owns, and whether it is holding each right now:
  // the cursor over the stack, the keyboard focus inside it, and a tab nobody is
  // looking at — walk away and everything is still there when you come back. The
  // host owes the store exactly one hold of each kind (mouseover and
  // visibilitychange both fire more than once), because a toast that closes
  // under the pointer takes the release event with it: Chrome and WebKit fire no
  // mouseleave and no focusout for an element that leaves the DOM, and they
  // never make it up afterwards. So both holds are given back by hand in
  // dismiss(), and the cursor's one is re-taken from mouseover rather than
  // mouseenter — mouseover also fires for the toast that slides under a cursor
  // standing still. They are signals because the life bar draws the freeze from
  // this very counter: the bar cannot drift apart from what the store was told,
  // since both come from here.
  private readonly held = {
    cursor: signal(false),
    focus: signal(false),
    tab: signal(false),
  }

  protected readonly frozen = computed(
    () => this.held.cursor() || this.held.focus() || this.held.tab(),
  )

  constructor() {
    // Mirror the store's stack and what did not fit in it, re-subscribing if the
    // input is swapped. Done in an effect rather than a field initializer because
    // an input is not readable until it is bound.
    effect((onCleanup) => {
      const store = this.store()
      this.toasts.set(store.toasts.get())
      this.overflow.set(store.overflow.get())
      const unsubscribeStack = subscribeSignal(store.toasts, (list) => {
        this.toasts.set(list)
      })
      const unsubscribeOverflow = subscribeSignal(store.overflow, (counts) => {
        this.overflow.set(counts)
      })
      onCleanup(() => {
        unsubscribeStack()
        unsubscribeOverflow()
      })
    })
    // Attached for as long as this host is rendered. While no viewer is attached
    // the store's countdown does not run at all, which is what keeps a notice that
    // arrived before the first frame from burning down behind the splash screen.
    // After render rather than in an effect, because the window belongs to the
    // browser and this component is also rendered on the server.
    afterRenderEffect((onCleanup) => {
      const store = this.store()
      const viewer = store.attach()
      this.viewer = viewer
      viewer.setViewportHeight(window.innerHeight)
      const onResize = (): void => {
        viewer.setViewportHeight(window.innerHeight)
      }
      const onVisibility = (): void => {
        this.setHold('tab', document.hidden)
      }
      onVisibility()
      window.addEventListener('resize', onResize)
      document.addEventListener('visibilitychange', onVisibility)
      onCleanup(() => {
        window.removeEventListener('resize', onResize)
        document.removeEventListener('visibilitychange', onVisibility)
        // detach gives back whatever holds this host was still holding, so the
        // bookkeeping of the ones it owns starts clean against the next viewer.
        viewer.detach()
        this.viewer = null
        this.held.tab.set(false)
        this.held.cursor.set(false)
        this.held.focus.set(false)
      })
    })
    // Measured after the browser has laid the cards out, and again after every
    // render: a card is only really on screen once the store knows how tall it is,
    // and every report after the first only updates the number. The cursor is
    // re-checked in the same place, and one way only: it takes the hold if the
    // stack turns out to be under the pointer and never gives it back. Chromium
    // does not re-check what is under the cursor when an element is ADDED, so a
    // stack that grew under a still pointer would otherwise never learn of it;
    // removal both engines re-check honestly, and releasing here instead would
    // need an environment that answers `:hover`.
    afterRenderEffect(() => {
      const viewer = this.viewer
      const cards = this.cards()
      const stack = this.toasts()
      if (viewer === null) {
        return
      }
      cards.forEach((card, index) => {
        const toast = stack[index]
        if (toast !== undefined) {
          viewer.reportHeight(toast.id, occupiedHeight(card.nativeElement))
        }
      })
      const container = this.stack()
      if (
        container !== undefined &&
        container.nativeElement.matches(':hover')
      ) {
        this.setHold('cursor', true)
      }
    })
  }

  /**
   * What names a severity on the card: the rail, the accent and the icon.
   *
   * @param severity The toast's severity.
   */
  protected visual(severity: HilosToastSeverity): ToastVisual {
    return TOAST_VISUAL[severity]
  }

  /**
   * The classes that keep one card out of sight while it is being measured.
   *
   * Only a rendered card can be measured, so the card stays in the loop and the
   * classes come off the moment the store admits it.
   *
   * @param toast The card being drawn.
   */
  protected measuring(toast: HilosToast): string {
    return toast.measured ? '' : MEASURING_LAYER
  }

  /**
   * The Bootstrap classes of a severity's icon: the glyph and its accent.
   *
   * Angular takes one `[class]` binding per element, so the pair from the table
   * is handed over as one string — the same shape HilosLayout uses for the
   * connection indicator.
   *
   * @param severity The toast's severity.
   */
  protected iconClass(severity: HilosToastSeverity): string {
    const visual = TOAST_VISUAL[severity]

    return `bi ms-1 ${visual.icon} ${visual.accent}`
  }

  /**
   * The line a screen reader reads out for one notice.
   *
   * A background notice names its sender: the listener would otherwise get news
   * with no idea who sent it, while the reader of the card sees the signature.
   *
   * @param toast The notice being announced.
   */
  protected spoken(toast: HilosToast): string {
    return toast.source === null
      ? toast.message
      : `${toast.source}: ${toast.message}`
  }

  /**
   * Take one of the three holds, or give it back.
   *
   * The one writing path: it records the hold and tells the store in the same
   * breath, so the life bar — which reads these very signals — cannot drift
   * apart from what the store was told. The kind travels with it (HIL-768): the
   * store freezes on all three alike but reports only the two a person is behind.
   *
   * @param kind Which hold is changing.
   * @param taken Whether the host is holding it from now on.
   */
  protected setHold(kind: HilosToastHoldReason, taken: boolean): void {
    const viewer = this.viewer
    const held = this.held[kind]
    if (held() === taken || (taken && viewer === null)) {
      return
    }
    held.set(taken)
    if (taken) {
      viewer?.hold(kind)

      return
    }
    viewer?.release(kind)
  }

  /**
   * Remove one notice early.
   *
   * @param id The toast id.
   */
  protected dismiss(id: number): void {
    this.setHold('cursor', false)
    this.setHold('focus', false)
    this.store().dismiss(id)
  }

  /**
   * Close the card whose link just took the reader somewhere else.
   *
   * Which clicks those are is not decided here a second time: HilosLink swallows
   * the event exactly when it navigated in place, while a modified click, a
   * non-primary button and a missing router leave it alone — and in those the
   * reader never left this page, so the notice stays. The close button bubbles
   * here too and is harmless: it swallows nothing, so the flag is off.
   *
   * @param event The click that reached the card.
   * @param id The toast id.
   */
  protected dismissIfNavigated(event: MouseEvent, id: number): void {
    if (event.defaultPrevented) {
      this.dismiss(id)
    }
  }

  /**
   * Freeze the countdown when keyboard focus arrives from outside the stack.
   *
   * @param event The bubbled focusin.
   */
  protected holdOnFocus(event: FocusEvent): void {
    if (!movesWithin(event)) {
      this.setHold('focus', true)
    }
  }

  /**
   * Release the focus hold once focus actually leaves the stack.
   *
   * @param event The bubbled focusout.
   */
  protected releaseOnBlur(event: FocusEvent): void {
    if (!movesWithin(event)) {
      this.setHold('focus', false)
    }
  }
}
