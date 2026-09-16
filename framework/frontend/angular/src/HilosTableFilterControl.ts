// HilosTableFilterControl — one declared filter of a table's bar, in whichever of
// the three shapes the page declared it: a dropdown, a date range, a toggle. The
// set of shapes is closed (tableFrame.ts, HilosTableFilter), so each of them has
// exactly one way of being drawn and the branch on `kind` lives in this one place.
// Every change is a call into the controller — a filter is never applied on the
// client — and a date range sends BOTH of its bounds in one window change, because
// two calls would show a window filtered by a start with no end. Internal to the
// Angular view layer on purpose: it is not exported from index.ts, for the reason
// the bar is not. The Angular port of the Vue reference
// (vue/src/HilosTableFilterControl.vue), under the same names and words.
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  computed,
  inject,
  input,
  signal,
} from '@angular/core'
import type {
  HilosTableFacetCount,
  HilosTableFilterView,
  TableViewportController,
} from '@hilos/core'

import { HilosDropdown } from './HilosDropdown.js'
import type { HilosDropdownOption } from './hilosDropdownOption.js'

// Distinct ids so two controls on one page never point a label at the same field.
let filterControlSeq = 0

// The value of an option is declared `unknown`, and the dropdown is typed
// `string | number` — so what travels through the primitive is the option's
// PLACE in the declared list, and the declared value itself is read back out of
// that list. Casting the value to a string would lose its type on the way back.
const NO_CHOICE = -1

/** One declared filter, drawn in the shape its declaration names. */
@Component({
  selector: 'hilos-table-filter-control',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosDropdown],
  template: `
    @switch (view().filter.kind) {
      @case ('select') {
        <div [attr.data-id]="dataId()">
          <hilos-dropdown
            [options]="dropdownOptions()"
            [value]="selectedIndex()"
            [menuAriaLabel]="view().filter.label"
            (valueChange)="onSelect($event)"
          >
            <ng-template #toggle let-label>
              <span class="text-truncate"
                >{{ view().filter.label }}: {{ label }}</span
              >
            </ng-template>
            <!-- Only once counts have arrived: until then, and for a table that
            does not count, the list is the one the primitive draws, not one with
            blanks in it. The item keeps the primitive's own shape, and the number
            stands at the right, muted on every item but the picked one, where it
            would not read. -->
            @if (view().facets !== null) {
              <ng-template
                #option
                let-option
                let-selected="selected"
                let-select="select"
              >
                <button
                  type="button"
                  class="dropdown-item d-flex align-items-center justify-content-between gap-2"
                  [class.active]="selected"
                  [disabled]="option.disabled"
                  role="option"
                  [attr.aria-selected]="selected"
                  [attr.data-id]="'hilos-dropdown-option-' + option.value"
                  (click)="select()"
                >
                  <span class="text-truncate">{{ option.label }}</span>
                  @if (facetText(option.value); as text) {
                    <span
                      class="ms-auto small flex-shrink-0"
                      [class.text-body-secondary]="!selected"
                      [attr.data-id]="facetDataId(option.value)"
                      >{{ text }}</span
                    >
                  }
                </button>
              </ng-template>
            }
          </hilos-dropdown>
        </div>
      }
      @case ('date_range') {
        <div class="dropdown">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            [attr.aria-expanded]="rangeOpen()"
            [attr.data-id]="dataId()"
            (click)="rangeOpen.set(!rangeOpen())"
            (keydown.escape)="onRangeEscape($event)"
          >
            {{ rangeLabel() }}
          </button>
          <div class="dropdown-menu show p-3" [class.d-none]="!rangeOpen()">
            <label [for]="fromId" class="form-label small mb-1">From</label>
            <input
              [id]="fromId"
              type="date"
              class="form-control form-control-sm mb-2"
              [value]="bounds().from"
              [attr.data-id]="dataId() + '-from'"
              (input)="onFrom($event)"
            />
            <label [for]="toId" class="form-label small mb-1">To</label>
            <input
              [id]="toId"
              type="date"
              class="form-control form-control-sm mb-2"
              [value]="bounds().to"
              [attr.data-id]="dataId() + '-to'"
              (input)="onTo($event)"
            />
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary w-100"
              [attr.data-id]="dataId() + '-clear'"
              (click)="setBounds('', '')"
            >
              Clear
            </button>
          </div>
        </div>
      }
      @case ('toggle') {
        <div class="form-check form-switch mb-0">
          <input
            [id]="toggleId"
            class="form-check-input"
            type="checkbox"
            [checked]="view().active"
            [attr.data-id]="dataId()"
            (change)="onToggle($event)"
          />
          <label [for]="toggleId" class="form-check-label">{{
            view().filter.label
          }}</label>
        </div>
      }
    }
  `,
})
export class HilosTableFilterControl<R> {
  /** The declared filter together with the value it currently holds. */
  readonly view = input.required<HilosTableFilterView>()
  /** The headless server-windowed controller every change is written into. */
  readonly controller = input.required<TableViewportController<R>>()
  /**
   * Where this copy of the control is drawn. The bar and the filters modal both
   * hold one at the same time, so the copy in the modal answers to its own names;
   * there is no default, because one would quietly give the two copies the same
   * name again.
   */
  readonly placement = input.required<'bar' | 'modal'>()

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef)
  private readonly seq = filterControlSeq++

  protected readonly fromId = `hilos-table-filter-control-${this.seq}-from`
  protected readonly toId = `hilos-table-filter-control-${this.seq}-to`
  protected readonly toggleId = `hilos-table-filter-control-${this.seq}-toggle`

  // Every handle of the modal's copy carries this at the end of the control's own
  // name, so each name in the document stays one element.
  private readonly placementSuffix = computed(() =>
    this.placement() === 'modal' ? '-modal' : '',
  )

  // A date range is one control over two keys, and the lower one names it: every
  // handle of this filter has to be found by one word from outside.
  private readonly filterKey = computed(() => {
    const filter = this.view().filter

    return filter.kind === 'date_range' ? filter.fromKey : filter.key
  })

  protected readonly dataId = computed(
    () => `hilos-table-filter-${this.filterKey()}${this.placementSuffix()}`,
  )

  // --- select ---------------------------------------------------------------

  private readonly declaredOptions = computed(() => {
    const filter = this.view().filter

    return filter.kind === 'select' ? filter.options() : []
  })

  // Read fresh inside a computed rather than copied into state: options that
  // arrive in the page scope later — the channels of a delivery log — redraw the
  // control on their own, and a copy is exactly what would freeze them.
  protected readonly dropdownOptions = computed<HilosDropdownOption<number>[]>(
    () => {
      const filter = this.view().filter
      const anyLabel =
        filter.kind === 'select' ? (filter.anyLabel ?? 'Any') : 'Any'

      return [
        { value: NO_CHOICE, label: anyLabel },
        ...this.declaredOptions().map((option, index) => ({
          value: index,
          label: option.label,
        })),
      ]
    },
  )

  protected readonly selectedIndex = computed(() => {
    const view = this.view()

    return view.active
      ? this.declaredOptions().findIndex(
          (option) => option.value === view.value,
        )
      : NO_CHOICE
  })

  protected onSelect(index: number | null): void {
    const filter = this.view().filter
    if (filter.kind !== 'select' || index === null) {
      return
    }
    this.controller().setFilter(
      filter.key,
      index === NO_CHOICE ? undefined : this.declaredOptions()[index]?.value,
    )
  }

  // --- select counts --------------------------------------------------------

  // The number beside an option is read out of the counts by the option's place,
  // the same place the primitive carries: "no choice" answers with the set the
  // filter lifted, a declared option with its own count, found under the text of
  // its value — which is how the server keys it.
  private facetCount(index: number): HilosTableFacetCount | undefined {
    const facets = this.view().facets
    if (facets === null) {
      return undefined
    }
    if (index === NO_CHOICE) {
      return facets.any
    }
    const option = this.declaredOptions()[index]

    return option === undefined
      ? undefined
      : facets.options.get(String(option.value))
  }

  // Three forms and no more: the number, "500+" where the count stopped at its
  // ceiling, and 0 — which is written, because "this leaves nothing" is the point.
  // Null where there is no count to write, and nothing is drawn there.
  protected facetText(index: number): string | null {
    const count = this.facetCount(index)
    if (count === undefined) {
      return null
    }

    return count.exact ? String(count.count) : `${count.count}+`
  }

  protected facetDataId(index: number): string {
    const option = this.declaredOptions()[index]
    const value =
      index === NO_CHOICE || option === undefined ? 'any' : String(option.value)

    return `hilos-table-facet-${this.filterKey()}-${value}${this.placementSuffix()}`
  }

  // --- date range -----------------------------------------------------------

  protected readonly rangeOpen = signal(false)

  protected readonly bounds = computed(() => {
    const view = this.view()
    const value = view.value

    if (
      view.filter.kind !== 'date_range' ||
      value === null ||
      value === undefined
    ) {
      return { from: '', to: '' }
    }
    const { from, to } = value as { from?: unknown; to?: unknown }

    return {
      from: from === undefined ? '' : String(from),
      to: to === undefined ? '' : String(to),
    }
  })

  // Four forms, one for each way a range can be half-open — a bound that is not
  // there is left out of the sentence rather than shown as an empty side.
  protected readonly rangeLabel = computed(() => {
    const label = this.view().filter.label
    const { from, to } = this.bounds()

    if (from !== '' && to !== '') {
      return `${label}: ${from} – ${to}`
    }
    if (from !== '') {
      return `${label}: from ${from}`
    }
    if (to !== '') {
      return `${label}: until ${to}`
    }

    return label
  })

  // The SDK ships Bootstrap's CSS and not its JS, so opening and closing the panel
  // is owned here, as it is in HilosDropdown.
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (
      this.rangeOpen() &&
      !this.host.nativeElement.contains(event.target as Node)
    ) {
      this.rangeOpen.set(false)
    }
  }

  protected onRangeEscape(event: Event): void {
    event.preventDefault()
    this.rangeOpen.set(false)
  }

  protected setBounds(from: string, to: string): void {
    const filter = this.view().filter
    if (filter.kind !== 'date_range') {
      return
    }
    this.controller().setFilters({ [filter.fromKey]: from, [filter.toKey]: to })
  }

  protected onFrom(event: Event): void {
    this.setBounds((event.target as HTMLInputElement).value, this.bounds().to)
  }

  protected onTo(event: Event): void {
    this.setBounds(this.bounds().from, (event.target as HTMLInputElement).value)
  }

  // --- toggle ---------------------------------------------------------------

  protected onToggle(event: Event): void {
    const filter = this.view().filter
    if (filter.kind !== 'toggle') {
      return
    }
    this.controller().setFilter(
      filter.key,
      (event.target as HTMLInputElement).checked ? filter.on : undefined,
    )
  }
}
