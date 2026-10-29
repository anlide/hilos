import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  viewChild,
} from '@angular/core'
import {
  hilosTableTextClass,
  isHiddenValue,
  type Hideable,
} from '@hilos/core/base'
import { HilosHiddenMark } from './HilosHiddenMark.js'
import {
  HILOS_TABLE_TEXT,
  TABLE_TEXT_COPY,
  createTableTextReading,
} from './hilosTableTextContext.js'

function partsContext() {
  const context = inject(HILOS_TABLE_TEXT, { optional: true })
  if (
    !context ||
    typeof context.value !== 'function' ||
    typeof context.policy !== 'function' ||
    typeof context.scope !== 'function' ||
    typeof context.regions !== 'function' ||
    typeof context.register !== 'function' ||
    context.value().heightContent !== 'parts'
  )
    throw new TypeError(
      'HilosTableText requires a heightContent: parts context.',
    )
  return context
}

/** The single main text region inside a heightContent: parts cell template. */
@Component({
  selector: 'hilos-table-text',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosHiddenMark],
  template: `
    @if (shown(); as shown) {
      <div>
        <div
          #target
          [id]="reading.contentId()"
          [class]="reading.textClass()"
          [class.is-open]="reading.expanded()"
        >
          <div #content>{{ shown.value }}</div>
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
    } @else if (context.policy().mode !== 'content') {
      <div class="hilos-stack">
        <div
          [class]="reserveClass()"
          class="is-open"
          aria-hidden="true"
          inert
        ></div>
        <div><hilos-hidden-mark /></div>
      </div>
    } @else {
      <hilos-hidden-mark />
    }
  `,
})
export class HilosTableText {
  /** The text as received, including the server's hidden-value mark. */
  readonly value = input.required<Hideable<string>>()

  protected readonly context = partsContext()
  protected readonly shown = computed(() => {
    const value = this.value()
    return isHiddenValue(value) ? null : { value }
  })
  private readonly target = viewChild<ElementRef<HTMLElement>>('target')
  private readonly content = viewChild<ElementRef<HTMLElement>>('content')
  protected readonly reserveClass = computed(() =>
    hilosTableTextClass(this.context.policy()),
  )
  protected readonly reading = createTableTextReading(
    this.context,
    () => this.target()?.nativeElement ?? null,
    () => this.content()?.nativeElement ?? null,
    () =>
      this.shown() !== null &&
      (this.context.policy().mode === 'content' ||
        this.context.regions() === 1),
  )
  protected readonly copy = TABLE_TEXT_COPY

  constructor() {
    inject(DestroyRef).onDestroy(this.context.register({}))
    effect(() => {
      if (this.context.value().heightContent !== 'parts')
        throw new TypeError(
          'HilosTableText requires a heightContent: parts context.',
        )
    })
  }
}
