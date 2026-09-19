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
      notices did not fit, under the newest card, in the canon's wording
      (docs/agents/frontend/toasts.md). Only the missed half is a control - it
      shows the oldest missed notice at once; queued errors arrive by themselves
      and need no door. Its accessible name contains its visible text, so speech
      input can name it (WCAG 2.5.3). It carries no #card, so the
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
          <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
          @if (overflow().waiting > 0) {
            <span>{{ overflow().waiting }} more waiting</span>
          }
          @if (overflow().waiting > 0 && overflow().missed > 0) {
            <span> · </span>
          }
          @if (overflow().missed > 0) {
            <button
              type="button"
              class="btn btn-link btn-sm p-0 border-0 align-baseline text-body-secondary"
              [attr.aria-label]="overflow().missed + ' missed, show one'"
              data-id="hilos-toast-missed"
              (click)="showMissed()"
            >
              {{ overflow().missed }} missed
            </button>
          }
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

  // The card the "N missed" control last put up, until the next pass that
  // reports heights: that pass is where the card is really on screen, and where
  // focus follows it if the press took the control away (HIL-908).
  private returned: number | null = null

  // The rendered cards, in the order the `@for` laid them out — which is the
  // order of `toasts()`, because they come from the same loop. Anything the
  // stack grows around the cards carries no `#card`, so it never shifts the
  // pairing — the container included, which is why it is `#stack`.
  private readonly cards = viewChildren<ElementRef<HTMLElement>>('card')

  private readonly stack = viewChild<ElementRef<HTMLElement>>('stack')

  private viewer: HilosToastViewer | null = null

  // The cards that were on screen at the last render: measured, so out of the
  // measuring layer and possibly under the pointer. One of them leaving is what
  // gives the cursor's hold back (see `held`).
  private visible = new Set<number>()

  // The three holds this host owns, and whether it is holding each right now:
  // the cursor over the stack, the keyboard focus inside it, and a tab nobody is
  // looking at — walk away and everything is still there when you come back. The
  // host owes the store exactly one hold of each kind (mouseover and
  // visibilitychange both fire more than once), because a card that leaves the
  // stack takes its release event with it: Chrome and WebKit fire no mouseleave
  // and no focusout for an element that leaves the DOM, and they never make it
  // up afterwards. So the cursor's hold is taken only by mouseover — which also
  // fires for the card that arrives or slides under a cursor standing still —
  // and given back by mouseleave or whenever a card that was on screen leaves
  // the stack, by any road: the close cross, the server's frame, an expiry, a
  // clear (followLeaving). Whatever is still under the pointer takes it again
  // from the next mouseover. The focus hold is given back by focusout or, after
  // a render, when focus is no longer inside the stack — a tree fact, not a
  // style. Nothing re-reads :hover after a render: that read answers with the
  // state from before the patch, and a hold it took was never given back
  // (HIL-916). Accepted gap: a pointer resting on a card that does not move
  // while another leaves by the keyboard or the server — the countdown runs
  // until the mouse moves. They are signals because the life bar draws the freeze from
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
        this.followLeaving(list)
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
    // and every report after the first only updates the number. The same pass
    // gives the focus hold back when focus has left the stack without a focusout
    // and remembers which cards are on screen.
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
      this.followReturned(cards, stack)
      const container = this.stack()
      if (
        this.held.focus() &&
        !(container?.nativeElement.contains(document.activeElement) ?? false)
      ) {
        this.setHold('focus', false)
      }
      this.visible = new Set(
        stack.filter((toast) => toast.measured).map((toast) => toast.id),
      )
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
    this.store().dismiss(id)
  }

  /**
   * Ask the store for the oldest missed notice. A press that raced a notice
   * coming back by itself gets nothing, and then nothing happens.
   */
  protected showMissed(): void {
    this.returned = this.store().showMissed()
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

  /**
   * Give the cursor's hold back when a card that was on screen has left the
   * stack.
   *
   * Runs as the store publishes the new list, before the redraw, so the
   * mouseover the engine sends for the card sliding into place always lands
   * after this release and takes the hold again. The ids that left are
   * forgotten at once, so a second publish before the redraw does not release
   * twice.
   *
   * @param list The stack the store has just published.
   */
  private followLeaving(list: readonly HilosToast[]): void {
    const present = new Set(list.map((toast) => toast.id))
    let left = false
    for (const id of this.visible) {
      if (!present.has(id)) {
        this.visible.delete(id)
        left = true
      }
    }
    if (left) {
      this.setHold('cursor', false)
    }
  }

  /**
   * Put focus on the card the last press brought back, if that press also took
   * the control away — the last missed notice leaves nothing to press again, and
   * focus must not fall out of the stack with it. While notices are still missed
   * the control stays and so does focus: the person is about to press again.
   *
   * The cards are paired with the stack BY POSITION, as the measuring pass pairs
   * them, so the returned card is found by its place in the stack.
   *
   * @param cards The rendered cards, in the order of the stack.
   * @param stack The stack those cards were rendered from.
   */
  private followReturned(
    cards: readonly ElementRef<HTMLElement>[],
    stack: readonly HilosToast[],
  ): void {
    const returned = this.returned
    if (returned === null) {
      return
    }
    this.returned = null
    const card = cards[stack.findIndex((toast) => toast.id === returned)]
    const container = this.stack()?.nativeElement
    if (
      card === undefined ||
      container?.querySelector('[data-id="hilos-toast-missed"]') !== null
    ) {
      return
    }
    card.nativeElement
      .querySelector<HTMLElement>('[data-id="hilos-toast-close"]')
      ?.focus()
  }
}
