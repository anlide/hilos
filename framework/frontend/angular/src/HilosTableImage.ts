// HilosTableImage — the picture at the top of a tile: the page's own cell template
// of the column the table declared as its image (`views.imageColumn`), stamped once
// straight into a 4:3 frame, and the framework's "No image" in that same frame when
// the template is missing or renders nothing. Internal to the live Tiles projection
// of HilosViewportTable; not exported from any barrel.
//
// The stamp is the frame's own child, with no wrapper of ours between them, the way
// the Vue reference (vue/src/HilosTableRecordCard.vue) stamps its slot: the ratio
// frame sizes only its direct children, so a page's bare `<img>` fills the frame
// in both SDKs instead of standing at its natural size and spilling over the tile.
//
// Vue leaves the fallback to the slot: it decides from the vnodes that a slot
// rendered nothing. Angular has no such answer for a template, and neither the
// TemplateRef nor the comment anchors Angular writes say anything about content.
// So the verdict is read off the DOM the stamp actually produced: an element or a
// text node with more than whitespace is a picture; comments and blank text are
// not. The fallback stands in the same frame and is left out of that reading by
// its own element, so its arrival never turns over the verdict that brought it.
// A page's `@if` can change the answer without any input changing, so a local
// MutationObserver re-reads it.
//
// The img, its src and alt, and what happens when the file fails to load stay with
// the page's template; the frame only holds the room and the fallback.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  input,
  signal,
  untracked,
  viewChild,
  type TemplateRef,
} from '@angular/core'
import { TABLE_VIEW_COPY } from '@hilos/core/base'
import type { HilosTableCellContext } from './HilosTableCell.js'

/** The stamp the verdict was read for, and what it read. */
interface ImageVerdict {
  readonly template: TemplateRef<HilosTableCellContext<unknown>> | null
  readonly context: HilosTableCellContext<unknown>
  readonly empty: boolean
}

/**
 * Whether the stamp in the frame renders anything a reader can see: an element, or
 * a text node with more than whitespace. Comments — Angular's anchors and the
 * page's own — and blank text do not count, and neither does the frame's own
 * fallback, which stands beside the stamp.
 *
 * @param node The frame the template is stamped into.
 * @param fallback The frame's "No image" while it stands there; never part of the stamp.
 * @returns Whether the stamp shows something; `false` reads as "No image".
 */
function rendersContent(node: HTMLElement, fallback: Element | null): boolean {
  for (const child of node.childNodes) {
    if (child === fallback) continue
    if (child.nodeType === Node.ELEMENT_NODE) return true
    if (
      child.nodeType === Node.TEXT_NODE &&
      (child.textContent ?? '').trim() !== ''
    )
      return true
  }
  return false
}

/**
 * The observer's callback reaches the inspection through a ticket that teardown
 * empties, so a delivery queued before a replacement or destroy reads nothing.
 *
 * @param ticket The holder of the current inspection, emptied at teardown.
 * @returns The callback to hand the observer.
 */
function inspectionCallback(ticket: { inspect: (() => void) | null }) {
  return () => ticket.inspect?.()
}

/** The picture of one tile, or "No image" in the same frame. */
@Component({
  selector: 'hilos-table-image',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  host: { class: 'd-block' },
  template: `
    <div
      #frame
      class="ratio ratio-4x3 bg-body-tertiary rounded-top border-bottom"
      [attr.data-id]="'hilos-table-tile-image-' + cellContext().rowKey"
    >
      <ng-container
        [ngTemplateOutlet]="template()"
        [ngTemplateOutletContext]="cellContext()"
      />
      @if (empty() === true) {
        <div
          #fallback
          class="d-flex flex-column align-items-center justify-content-center text-body-secondary gap-1"
        >
          <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
          <span class="small">{{ copy.noImage }}</span>
        </div>
      }
    </div>
  `,
})
export class HilosTableImage {
  /** The page's cell template of the image column; `null` when it declared none. */
  readonly template = input.required<TemplateRef<
    HilosTableCellContext<unknown>
  > | null>()
  /** The same context every cell template receives: the row and its key. */
  readonly cellContext = input.required<HilosTableCellContext<unknown>>()

  protected readonly copy = TABLE_VIEW_COPY

  private readonly frame = viewChild.required<ElementRef<HTMLElement>>('frame')
  private readonly fallback = viewChild<ElementRef<HTMLElement>>('fallback')
  private readonly verdict = signal<ImageVerdict | null>(null)

  // A verdict counts only for the template and context it was read for. Between
  // a replacement and its inspection the answer is unknown, and the fallback
  // stays away rather than standing beside a picture that has just arrived.
  protected readonly empty = computed(() => {
    const verdict = this.verdict()

    return verdict !== null &&
      verdict.template === this.template() &&
      verdict.context === this.cellContext()
      ? verdict.empty
      : null
  })

  constructor() {
    // One observer per stamp: the node, the template or the context replaced
    // disconnects the old one and empties its ticket before the new one reads.
    // The same cleanup runs when the component is destroyed. The fallback is read
    // at each inspection, untracked: it comes and goes inside the observed frame,
    // and that is a delivery to read past, not a stamp to observe anew.
    afterRenderEffect((onCleanup) => {
      const node = this.frame().nativeElement
      const template = this.template()
      const context = this.cellContext()
      const ticket: { inspect: (() => void) | null } = {
        inspect: () =>
          this.verdict.set({
            template,
            context,
            empty: !rendersContent(
              node,
              untracked(() => this.fallback())?.nativeElement ?? null,
            ),
          }),
      }
      const inspect = inspectionCallback(ticket)
      const observer =
        typeof MutationObserver === 'undefined'
          ? null
          : new MutationObserver(inspect)
      observer?.observe(node, {
        childList: true,
        subtree: true,
        characterData: true,
      })
      onCleanup(() => {
        ticket.inspect = null
        observer?.disconnect()
      })
      inspect()
    })
  }
}
