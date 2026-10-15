// The Angular names table, shared by the language and country names pages: the
// code and native name, the "No name" plate told from a stored empty name, the
// chevron only on a row with corrections, the corrections inside the table's own
// panel and inside the card's, and the subject page's two action templates
// stamped with the row and the correction in both projections — live writes
// included. The frame and the expandable rule are the core's and tested there.
import { ChangeDetectionStrategy, Component, signal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_LANGUAGE_NAMES,
  ScopeManager,
  createHilosI18nNamesTable,
  createSignal,
} from '@hilos/core'
import type {
  ActionLifecycle,
  HilosConnection,
  HilosI18nNameRow,
  HilosI18nNamesTarget,
  TableRow,
  TableViewportController,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosI18nNamesTable } from '../src/admin/i18n/details/HilosI18nNamesTable.js'
import { englishName, frenchName, spanishName } from './helpers/hilosI18n.js'

/** A host handing the table both action templates, or none. */
@Component({
  selector: 'test-names-host',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nNamesTable],
  template: `
    <hilos-i18n-names-table
      [controller]="controller()"
      [nameActions]="withActions() ? base : null"
      [correctionActions]="withActions() ? correction : null"
    />
    <ng-template #base let-row>
      <span [attr.data-id]="'test-base-' + row.code">{{
        row.name ?? 'absent'
      }}</span>
    </ng-template>
    <ng-template #correction let-row let-correction="correction">
      <span [attr.data-id]="'test-correction-' + correction.localeCode"
        >{{ row.code }}:{{ correction.name ?? 'inherited' }}</span
      >
    </ng-template>
  `,
})
class NamesHost {
  // Plain signals, not inputs: the JIT transform that makes a signal input
  // visible runs over src only (vitest.config.ts).
  readonly controller = signal<TableViewportController<HilosI18nNameRow>>(
    null as never,
  )
  readonly withActions = signal(false)
}

/**
 * A names row as the backend sends it, its slot keyed `language`.
 *
 * @param row The view-model the slot carries.
 */
function nameRow(row: HilosI18nNameRow): TableRow {
  return { rowKey: row.code, slots: { language: row } }
}

/**
 * The names table of Українська with English, Spanish and French rows.
 *
 * @param target Which of the two names tables.
 * @param withActions Whether the host hands its action templates.
 */
function mountNames(
  target: HilosI18nNamesTarget = HILOS_I18N_LANGUAGE_NAMES,
  withActions = false,
) {
  const table = createHilosI18nNamesTable(
    {
      connection: {} as HilosConnection,
      scopes: new ScopeManager(),
      actions: {} as ActionLifecycle,
    },
    target,
    createSignal('uk'),
  )
  table.controller.ingestWindow(
    [nameRow(englishName), nameRow(spanishName), nameRow(frenchName)],
    3,
    true,
    null,
    null,
    100,
  )
  const fixture = TestBed.createComponent(NamesHost)
  fixture.componentInstance.controller.set(table.controller)
  fixture.componentInstance.withActions.set(withActions)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  document.body.append(root)

  return {
    controller: table.controller,
    root,
    detect: () => fixture.detectChanges(),
    one: (selector: string) => root.querySelector<HTMLElement>(selector),
  }
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('HilosI18nNamesTable', () => {
  it('shows the language by its code in capitals with its native name', () => {
    const h = mountNames()
    const row = h.one('tr[data-id="hilos-table-row-en"]')!
    expect(row.querySelector('.text-uppercase')?.textContent?.trim()).toBe('en')
    expect(row.textContent).toContain('English')
  })

  it('says "No name" only where nothing is stored and leaves a stored empty name empty', () => {
    const h = mountNames()
    expect(
      h.one('tr [data-id="i18n-names-none-fr"]')?.textContent?.trim(),
    ).toBe('No name')
    expect(h.one('[data-id="i18n-names-none-es"]')).toBeNull()
    expect(h.one('[data-id="i18n-names-none-en"]')).toBeNull()
    expect(h.one('tr[data-id="hilos-table-row-en"]')?.textContent).toContain(
      'Ukrainian',
    )
  })

  it('offers the chevron only on a row with corrections', () => {
    const h = mountNames()
    expect(h.one('tr [data-id="hilos-table-expand-en"]')).not.toBeNull()
    expect(h.one('[data-id="hilos-table-expand-es"]')).toBeNull()
    expect(h.one('[data-id="hilos-table-expand-fr"]')).toBeNull()
  })

  it('lists the corrections inside the row panel and the card, an inherited one as "as in" the native name', () => {
    const h = mountNames()
    h.controller.expandRow('en', true)
    h.detect()
    for (const surface of [
      'tr[data-id="hilos-table-row-detail-en"]',
      '[data-id="hilos-table-cards"]',
    ]) {
      const panel = h.one(surface)!
      const british = panel.querySelector(
        '[data-id="i18n-names-correction-en-GB"]',
      )!
      expect(british.textContent).toContain('United Kingdom')
      expect(british.textContent).toContain('Ukrainian (UK)')
      // A country with no name in the default language is labelled by its code.
      expect(
        panel.querySelector('[data-id="i18n-names-correction-en-US"]')
          ?.textContent,
      ).toContain('US')
      expect(
        panel
          .querySelector('[data-id="i18n-names-inherit-en-CA"]')
          ?.textContent?.trim(),
      ).toBe('as in “English”')
      expect(
        panel.querySelector('[data-id="i18n-names-inherit-en-US"]'),
      ).toBeNull()
      expect(
        panel.querySelector('[data-id="i18n-names-inherit-en-GB"]'),
      ).toBeNull()
    }
    // The panel is the table's own, under the row: no second section after it.
    expect(
      h.one('tr[data-id="hilos-table-row-detail-en"] dl > div')?.className,
    ).toContain('col-md-12')
  })

  it('leaves the action cell empty when the page hands no templates', () => {
    const h = mountNames(HILOS_I18N_COUNTRY_NAMES)
    h.controller.expandRow('en', true)
    h.detect()
    expect(
      [...h.root.querySelectorAll('button')].every((button) =>
        button.getAttribute('data-id')?.startsWith('hilos-table-expand-'),
      ),
    ).toBe(true)
    expect(
      [...h.root.querySelectorAll('th')].filter(
        (cell) => cell.textContent?.trim() === 'Actions',
      ),
    ).toHaveLength(1)
  })

  it.each([HILOS_I18N_LANGUAGE_NAMES, HILOS_I18N_COUNTRY_NAMES])(
    'stamps the row and the correction into both projections for $page',
    (target) => {
      const h = mountNames(target, true)
      h.controller.expandRow('en', true)
      h.detect()
      for (const surface of ['table', '[data-id="hilos-table-cards"]']) {
        const text = (id: string) =>
          h.one(`${surface} [data-id="${id}"]`)?.textContent?.trim()
        expect(text('test-base-en')).toBe('Ukrainian')
        expect(text('test-base-es')).toBe('')
        expect(text('test-base-fr')).toBe('absent')
        expect(text('test-correction-en-GB')).toBe('en:Ukrainian (UK)')
        expect(text('test-correction-en-US')).toBe('en:')
        expect(text('test-correction-en-CA')).toBe('en:inherited')
      }
    },
  )

  it('keeps both projections in step when an absent value becomes a stored empty one', () => {
    const h = mountNames(HILOS_I18N_COUNTRY_NAMES, true)
    h.controller.expandRow('en', true)
    h.detect()
    h.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'fr',
      row: nameRow({ ...frenchName, name: '' }),
    })
    h.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'en',
      row: nameRow({
        ...englishName,
        corrections: englishName.corrections.map((correction) => ({
          ...correction,
          name: '',
        })),
      }),
    })
    h.detect()
    for (const surface of ['table', '[data-id="hilos-table-cards"]']) {
      expect(
        h.one(`${surface} [data-id="test-base-fr"]`)?.textContent?.trim(),
      ).toBe('')
      expect(
        h
          .one(`${surface} [data-id="test-correction-en-CA"]`)
          ?.textContent?.trim(),
      ).toBe('en:')
    }
    expect(h.one('[data-id="i18n-names-none-fr"]')).toBeNull()
    expect(h.one('[data-id="i18n-names-inherit-en-CA"]')).toBeNull()
    // A value changes at once; only a move or a removal waits for Apply.
    expect(h.one('[data-id="hilos-table-apply"]')).toBeNull()
  })
})
