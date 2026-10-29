import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  Injector,
  afterRenderEffect,
  computed,
  inject,
  input,
  isDevMode,
  signal,
  viewChild,
  type TemplateRef,
} from '@angular/core'
import type { HilosTableCellContext } from './HilosTableCell.js'
import {
  HILOS_TABLE_TEXT,
  TABLE_TEXT_COPY,
  createTableTextReading,
  type HilosTableTextContext,
  type HilosTableValueProps,
} from './hilosTableTextContext.js'

// Actual rendered descendants, including consumer-component output, decide safety.
const INLINE_TEXT = new Set(
  'SPAN B STRONG I EM U S SMALL MARK SUB SUP BR WBR CODE KBD SAMP VAR ABBR BDI BDO DEL INS Q CITE TIME RUBY RP RT'.split(
    ' ',
  ),
)

function ordinaryText(node: HTMLElement): boolean {
  return [...node.querySelectorAll('*')].every((element) => {
    if (
      // A consumer's custom-element host may wrap ordinary inline output.
      (!INLINE_TEXT.has(element.tagName) && !element.tagName.includes('-')) ||
      element.hasAttribute('tabindex') ||
      element.hasAttribute('contenteditable') ||
      element.hasAttribute('role') ||
      element.hasAttribute('onclick') ||
      element.matches('.badge, [data-id="hilos-hidden"]')
    )
      return false
    return !/^(block|flow-root|flex|grid|table|list-item|inline-flex|inline-grid|inline-block)/.test(
      getComputedStyle(element).display,
    )
  })
}

/** Queued deliveries capture a ticket emptied at teardown, never the component. */
function inspectionCallback(ticket: { inspect: (() => void) | null }) {
  return () => ticket.inspect?.()
}

/** Internal template wrapper; logical text intent remains in the core controller. */
@Component({
  selector: 'hilos-table-value',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  template: `
    @if (text()) {
      <div>
        <div
          #target
          [id]="reading.contentId()"
          [class]="textClass()"
          [class.is-open]="safe() === true && reading.expanded()"
        >
          <div #content>
            <ng-container
              [ngTemplateOutlet]="template()"
              [ngTemplateOutletContext]="cellContext()"
              [ngTemplateOutletInjector]="templateInjector"
            />
          </div>
        </div>
        @if (reading.limited()) {
          <div class="hilos-stack">
            <span class="invisible" aria-hidden="true" inert
              ><span class="btn btn-link btn-sm p-0">{{
                copy.expand
              }}</span></span
            >
            <div>
              @if (reading.showControl()) {
                <button
                  type="button"
                  class="btn btn-link btn-sm p-0"
                  data-id="hilos-table-text-toggle"
                  [attr.aria-label]="reading.accessibleName()"
                  [attr.aria-expanded]="reading.expanded()"
                  [attr.aria-controls]="reading.contentId()"
                  (click)="$event.stopPropagation(); reading.toggle()"
                  (keydown.enter)="$event.stopPropagation()"
                  (keydown.space)="$event.stopPropagation()"
                  (focus)="reading.setFocused(true)"
                  (blur)="reading.setFocused(false)"
                >
                  {{ reading.caption() }}
                </button>
              }
            </div>
          </div>
        }
      </div>
    } @else {
      <ng-container
        [ngTemplateOutlet]="template()"
        [ngTemplateOutletContext]="cellContext()"
        [ngTemplateOutletInjector]="templateInjector"
      />
    }
  `,
})
export class HilosTableValue {
  readonly template =
    input.required<TemplateRef<HilosTableCellContext<unknown>>>()
  readonly cellContext = input.required<HilosTableCellContext<unknown>>()
  readonly value = input.required<HilosTableValueProps>()
  protected readonly text = computed(
    () => this.value().heightContent === 'text',
  )
  private readonly verdict = signal<{
    template: TemplateRef<HilosTableCellContext<unknown>>
    row: unknown
    rowKey: string
    controller: HilosTableValueProps['controller']
    columnKey: string
    projection: HilosTableValueProps['projection']
    safe: boolean
  } | null>(null)
  protected readonly safe = computed(() => {
    const verdict = this.verdict()
    const value = this.value()
    const cell = this.cellContext()
    return verdict &&
      verdict.template === this.template() &&
      verdict.row === cell.$implicit &&
      verdict.rowKey === cell.rowKey &&
      verdict.controller === value.controller &&
      verdict.columnKey === value.columnKey &&
      verdict.projection === value.projection
      ? verdict.safe
      : null
  })
  private readonly target = viewChild<ElementRef<HTMLElement>>('target')
  private readonly content = viewChild<ElementRef<HTMLElement>>('content')
  private readonly regions = signal(0)
  private readonly registrations = new Map<object, () => void>()
  private readonly context: HilosTableTextContext = {
    value: this.value,
    policy: () => this.value().controller.frame.rowHeight,
    scope: () => {
      const value = this.value()
      const record = value.readRecord()
      return record
        ? {
            controller: value.controller,
            record,
            columnKey: value.columnKey,
            projection: value.projection,
          }
        : null
    },
    regions: this.regions.asReadonly(),
    register: (region) => {
      const registered = this.registrations.get(region)
      if (registered) return registered
      const release = () => {
        if (this.registrations.get(region) !== release) return
        this.registrations.delete(region)
        this.regions.set(this.registrations.size)
      }
      this.registrations.set(region, release)
      this.regions.set(this.registrations.size)
      return release
    },
  }
  protected readonly templateInjector = Injector.create({
    providers: [{ provide: HILOS_TABLE_TEXT, useValue: this.context }],
    parent: inject(Injector),
  })
  protected readonly reading = createTableTextReading(
    this.context,
    () => this.target()?.nativeElement ?? null,
    () => this.content()?.nativeElement ?? null,
    // A new render waits for its DOM verdict without destroying a previously
    // safe value's focused control. Only the visual cap waits for inspection.
    () =>
      this.text() &&
      (this.safe() === true ||
        (this.safe() === null && this.verdict()?.safe === true)),
  )
  protected readonly textClass = computed(() =>
    this.safe() === false
      ? ''
      : this.safe() === null
        ? 'hilos-table-text'
        : this.reading.textClass(),
  )
  protected readonly copy = TABLE_TEXT_COPY

  constructor() {
    let inspected: HTMLElement | null = null
    let diagnosed = false
    let cleanup: (() => void) | null = null
    const inspectText = () => {
      const node = this.content()?.nativeElement
      if (!node) return
      const safe = ordinaryText(node)
      const value = this.value()
      const cell = this.cellContext()
      if (this.safe() !== safe)
        this.verdict.set({
          template: this.template(),
          row: cell.$implicit,
          rowKey: cell.rowKey,
          controller: value.controller,
          columnKey: value.columnKey,
          projection: value.projection,
          safe,
        })
      if (!safe && !diagnosed && isDevMode())
        console.warn(
          `HilosTableValue: unsafe text slot for ${value.columnKey}; use heightContent: 'parts' and HilosTableText.`,
        )
      diagnosed = !safe
    }
    afterRenderEffect(() => {
      const node = this.content()?.nativeElement ?? null
      this.template()
      this.cellContext()
      const text = this.text()
      if (node !== inspected) {
        cleanup?.()
        inspected = node
        if (!node) {
          this.verdict.set(null)
          cleanup = null
          return
        }
        const ticket = { inspect: inspectText as (() => void) | null }
        const inspect = inspectionCallback(ticket)
        const mutations =
          typeof MutationObserver === 'undefined'
            ? null
            : new MutationObserver(inspect)
        const resize =
          typeof ResizeObserver === 'undefined'
            ? null
            : new ResizeObserver(inspect)
        mutations?.observe(node, {
          childList: true,
          subtree: true,
          characterData: true,
          attributes: true,
        })
        for (
          let parent = node.parentElement;
          parent;
          parent = parent.parentElement
        )
          mutations?.observe(parent, {
            attributes: true,
            attributeFilter: ['class', 'style', 'hidden'],
          })
        resize?.observe(node)
        node.ownerDocument.defaultView?.addEventListener('resize', inspect)
        cleanup = () => {
          ticket.inspect = null
          mutations?.disconnect()
          resize?.disconnect()
          node.ownerDocument.defaultView?.removeEventListener('resize', inspect)
        }
        inspect()
      } else if (text && node) inspectText()
    })
    // Constructors register the stamped children during Angular's render. Read
    // the final tree here, after conditional/template replacement has settled.
    afterRenderEffect(() => {
      if (
        this.value().heightContent === 'parts' &&
        this.context.policy().mode !== 'content' &&
        this.regions() !== 1
      )
        throw new TypeError(
          'HilosTableValue: limited parts requires exactly one HilosTableText.',
        )
    })
    inject(DestroyRef).onDestroy(() => {
      cleanup?.()
      cleanup = null
      inspected = null
      this.verdict.set(null)
      this.registrations.clear()
      this.regions.set(0)
      this.templateInjector.destroy()
    })
  }
}
