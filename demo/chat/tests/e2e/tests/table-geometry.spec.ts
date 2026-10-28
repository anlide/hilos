import { test, expect, type Page, type TestInfo } from '@playwright/test'
import { TABLE_VIEW_STORAGE_KEY } from '../../../../../framework/frontend/core/src/table/tableViewPreference.js'

import {
  clickSubmit,
  expectDataFreeDeparture,
  expectInertTableHolders,
  expectTableCardContentFits,
  expectTableProgressCoverage,
  expectTableRowOrder,
  expectTableWrappingStress,
  fitsInScrollport,
  openTableFixture,
  settleTableLayout,
  shownByTestId,
  watchHeight,
  watchTableGeometry,
  watchTableHandoff,
  watchTableFirstLive,
  watchTableAllocation,
  setTableEnvironment as setBrowserTableEnvironment,
  loadLateTableFont,
  expectTableLayoutZoom,
  watchTableNativeLifecycle,
  expectTableNativeLifecycle,
  expectTableTilePictures,
  expectTableTileDeparture,
  watchTableGeometryCallbacks,
  watchTableModalFocus,
  expectTableTextTrack,
  tableTextRegion,
  tableTextToggle,
  expectTableTextControl,
  expectTableTextGeometry,
  expectTableTextParts,
  expectTableIntrinsicRows,
  watchTableTextRoom,
  watchTableTextInline,
  type TableTextPolicy,
  type TableEnvironment,
  type TableGeometryWatch,
  type TableGeometryProjection,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

// Real Chromium layout of the public Vue renderer and shipped SDK Sass.
// Controller/store inputs are synthetic. Server delivery belongs to bots.spec.
const SURVIVORS = ['d', 'e', 'f']
const observations = new Map<Page, TableGeometryWatch[]>()
const REASONS = [
  ['deleted', 'Removed'],
  ['left_set', 'No longer in this list'],
  ['moved_out', 'Moved to another page'],
  ['future_reason', 'Removed'],
] as const

test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

async function press(page: Page, id: string): Promise<void> {
  await clickSubmit(expect, shownByTestId(page, id))
}

async function observeAllocation(
  page: Page,
  key: string,
  projection: TableGeometryProjection,
  generic = false,
) {
  const observation = await watchTableAllocation(
    expect,
    page,
    key,
    projection,
    generic,
    'd',
  )
  observations.set(page, [...(observations.get(page) ?? []), observation])
  return observation
}

async function setTableEnvironment(
  page: Page,
  change: TableEnvironment,
): Promise<void> {
  await setBrowserTableEnvironment(page, change, 'geometry-body')
}

async function setNumber(page: Page, id: string, value: number): Promise<void> {
  const input = page.getByTestId(id)
  await typeInto(input, String(value))
}

async function retired(
  page: Page,
  key: string,
  reason = 'Removed',
): Promise<void> {
  await expectDataFreeDeparture(expect, page, key, reason, [
    `geometry-name-${key}`,
    `geometry-text-${key}`,
    `geometry-badge-${key}`,
    `geometry-action-${key}`,
    `geometry-detail-${key}`,
    `geometry-work-${key}`,
  ])
}

async function retiredData(page: Page, key: string): Promise<void> {
  // A delayed request may already show the legitimate loading skeleton.
  // Neither that state nor the still-standing slot may expose retired values.
  for (const prefix of [
    'geometry-name-',
    'geometry-text-',
    'geometry-badge-',
    'geometry-action-',
    'geometry-detail-',
    'geometry-work-',
  ]) {
    await expect(page.getByTestId(`${prefix}${key}`)).toHaveCount(0)
  }
  await expect(page.getByTestId('geometry-state')).toContainText(
    `${key}:departed`,
  )
}

async function evidence(
  watch: TableGeometryWatch,
  page: Page,
  info: TestInfo,
  name: string,
): Promise<void> {
  // Keep recording through observer/font work; checking every captured frame
  // preserves a transient drift even when the final layout recovers.
  await settleTableLayout(page)
  await watch.stop()
  await info.attach(`${name}-bounds`, {
    body: await watch.evidence(),
    contentType: 'application/json',
  })
  await info.attach(`${name}-layout`, {
    body: await page.getByTestId('hilos-viewport-table').screenshot(),
    contentType: 'image/png',
  })
  await watch.check()
}

test.describe('wide', () => {
  const projection = 'wide'
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 })
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await settleTableLayout(page)
  })

  test.afterEach(async ({ page }, info) => {
    for (const [index, observation] of (
      observations.get(page) ?? []
    ).entries()) {
      await observation.stop()
      if (info.status !== info.expectedStatus) {
        await info.attach(`failure-${index}-bounds`, {
          body: await observation.evidence(),
          contentType: 'application/json',
        })
      }
    }
    observations.delete(page)
    if (info.status !== info.expectedStatus) {
      await info.attach('failure-layout', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
      })
    }
  })

  async function watch(page: Page, includeTarget = true) {
    const observation = await watchTableGeometry(expect, page, {
      bodyId: 'geometry-body',
      projection,
      rowKeys: includeTarget ? ['a', 'b', 'c', ...SURVIVORS, 'g'] : SURVIVORS,
      lastKey: 'g',
    })
    observations.set(page, [...(observations.get(page) ?? []), observation])
    return observation
  }

  for (const mode of ['sequential', 'batch'] as const) {
    test(`G01/G04 ${mode}: ordinary, widest, tallest and last retain the whole occupied extent`, async ({
      page,
    }, info) => {
      await page
        .getByTestId('geometry-edge')
        .selectOption(mode === 'batch' ? 'end' : 'start')
      await page.getByTestId('geometry-target').selectOption('c')
      await page.getByTestId('geometry-detail').selectOption('expanded')
      await page.getByTestId('geometry-text').check()
      await press(page, 'geometry-configure')
      await press(page, 'geometry-work')
      await expect(shownByTestId(page, 'geometry-detail-c')).toBeVisible()
      await expect(shownByTestId(page, 'geometry-work-c')).toBeVisible()
      if (projection === 'wide')
        await expectTableProgressCoverage(expect, page, 'c', 'geometry-text-c')
      await settleTableLayout(page)
      await info.attach('wrapping-condition', {
        body: await expectTableWrappingStress(
          expect,
          page,
          'geometry-name-b',
          'geometry-name-a',
          'geometry-text-d',
        ),
        contentType: 'application/json',
      })
      const observed = await watch(page)
      if (mode === 'batch') {
        await press(page, 'geometry-batch')
      } else {
        for (const key of ['a', 'b', 'c', 'g']) {
          await page.getByTestId('geometry-target').selectOption(key)
          await press(page, 'geometry-remove')
          await expect(page.getByTestId('geometry-state')).toContainText(
            `${key}:remove`,
          )
          if (key === 'c') {
            await expect(page.getByTestId('geometry-work-c')).toHaveCount(0)
            await observed.check()
            await press(page, 'geometry-complete')
            await observed.check()
          }
          await press(page, 'geometry-apply')
          await retired(page, key)
          await observed.check()
        }
      }
      for (const key of ['a', 'b', 'c', 'g']) await retired(page, key)
      await evidence(observed, page, info, mode)
    })
  }

  test('G11 initial empty to first live actions keeps the original mount and clear targets', async ({
    page,
  }, info) => {
    await press(page, 'geometry-unmount')
    await page.getByTestId('geometry-icon-actions').check()
    await press(page, 'geometry-replace')
    await page.getByTestId('geometry-snapshot').selectOption('empty')
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'empty',
    )
    await press(page, 'geometry-mount')
    await expect(shownByTestId(page, 'hilos-table-empty')).toBeVisible()
    await expect(page.getByTestId('hilos-table-row-a')).toHaveCount(0)
    await settleTableLayout(page)
    const first = await watchTableFirstLive(expect, page, 'geometry-body', [
      'geometry-details-a',
      'geometry-restore-a',
      'geometry-delete-a',
    ])
    try {
      await press(page, 'geometry-append')
      await expect(page.getByTestId('hilos-table-row-a')).toBeVisible()
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-body',
        'rows',
      )
      await settleTableLayout(page)
      await info.attach('first-live-bounds', {
        body: await first.evidence(),
        contentType: 'application/json',
      })
      await info.attach('first-live-layout', {
        body: await page.getByTestId('hilos-viewport-table').screenshot(),
        contentType: 'image/png',
      })
      await first.check()
      for (const action of ['details', 'restore', 'delete']) {
        await press(page, `geometry-${action}-a`)
        await expect(page.getByTestId('geometry-actions')).toContainText(
          `a:${action}`,
        )
      }
      const details = shownByTestId(page, 'geometry-details-a')
      await details.focus()
      for (const action of ['details', 'restore', 'delete']) {
        await expect(shownByTestId(page, `geometry-${action}-a`)).toBeFocused()
        await page.keyboard.press('Enter')
        if (action !== 'delete') await page.keyboard.press('Tab')
      }
      await expect(page.getByTestId('geometry-actions')).toHaveText(
        'a:details,a:restore,a:delete,a:details,a:restore,a:delete',
      )
      const retained = await watchTableAllocation(expect, page, 'a', 'wide')
      observations.set(page, [...(observations.get(page) ?? []), retained])
      await press(page, 'geometry-own')
      await retired(page, 'a')
      await retained.check()
      await page.getByTestId('geometry-target').selectOption('b')
      await press(page, 'geometry-append')
      await expect(page.getByTestId('hilos-table-row-b')).toBeVisible()
      await retained.check()
      await info.attach('first-live-retained-bounds', {
        body: await retained.evidence(),
        contentType: 'application/json',
      })
    } finally {
      await info.attach('first-live-frame-history', {
        body: await first.history(),
        contentType: 'application/json',
      })
      await first.stop()
    }
  })

  test('G09 auto to fixed calibration keeps every live boundary', async ({
    page,
  }, info) => {
    await press(page, 'geometry-unmount')
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
    const observed = await watchTableHandoff(expect, page, 'geometry-body')
    await press(page, 'geometry-mount')
    await expect(page.getByTestId('hilos-table-row-a')).toBeVisible()
    await settleTableLayout(page)
    await evidence(observed, page, info, 'auto-fixed-handoff')
  })

  for (const [reason, text] of REASONS) {
    test(`G02 ${reason}: departure carries only its reason in both mounted trees`, async ({
      page,
    }, info) => {
      await page.getByTestId('geometry-reason').selectOption(reason)
      const observed = await watch(page)
      await press(page, 'geometry-remove')
      await expect(page.getByTestId('geometry-state')).toContainText('a:remove')
      await observed.check()
      await press(page, 'geometry-apply')
      await retired(page, 'a', text)
      await expectInertTableHolders(expect, page)
      await evidence(observed, page, info, reason)
    })
  }

  test('G02 an in-window move applies as movement with live data and no departure reason', async ({
    page,
  }) => {
    await page.getByTestId('geometry-pending').selectOption('move')
    await press(page, 'geometry-configure')
    await expect(page.getByTestId('geometry-state')).toContainText('a:move')
    await press(page, 'geometry-apply')
    await expect(page.getByTestId('geometry-state')).toHaveText(
      'b:live,a:live,c:live,d:live,e:live,f:live,g:live',
    )
    await expectTableRowOrder(expect, page, ['b', 'a', 'c', 'd', 'e', 'f', 'g'])
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Record a')
    await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
  })

  for (const open of [false, true]) {
    for (const work of [false, true]) {
      for (const text of [false, true]) {
        test(`G03/G04 detail=${open} work=${work} long-synthetic-text=${text}`, async ({
          page,
        }, info) => {
          await page
            .getByTestId('geometry-detail')
            .selectOption(open ? 'expanded' : 'collapsed')
          await page.getByTestId('geometry-text').setChecked(text)
          await press(page, 'geometry-configure')
          if (work) await press(page, 'geometry-work')
          if (open)
            await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
          if (work)
            await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
          await settleTableLayout(page)
          const observed = await watch(page)
          await press(page, 'geometry-remove')
          await expect(page.getByTestId('geometry-state')).toContainText(
            'a:remove',
          )
          await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
          await observed.check()
          await press(page, 'geometry-apply')
          await retired(page, 'a')
          await press(page, 'geometry-complete')
          await evidence(
            observed,
            page,
            info,
            `detail-${open}-work-${work}-text-${text}`,
          )
        })
      }
    }
  }

  test('G04 completion before departure is not restored', async ({
    page,
  }, info) => {
    await press(page, 'geometry-work')
    await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
    await press(page, 'geometry-complete')
    await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
    await settleTableLayout(page)
    const observed = await watch(page)
    await press(page, 'geometry-own')
    await retired(page, 'a')
    await evidence(observed, page, info, 'completed-before')
  })

  test('G03 Q1=A retains the expanded detail/work/text allocation after layout invalidation', async ({
    page,
  }) => {
    await page.getByTestId('geometry-detail').selectOption('expanded')
    await page.getByTestId('geometry-text').check()
    await press(page, 'geometry-configure')
    await press(page, 'geometry-work')
    await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
    await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
    await press(page, 'geometry-own')
    await retired(page, 'a')
    const allocation = await watchHeight(
      page.getByTestId(
        `hilos-table-${projection === 'wide' ? 'row' : 'card'}-a`,
      ),
    )
    await page.setViewportSize({
      width: 1280,
      height: 1000,
    })
    await settleTableLayout(page)
    await allocation.unchanged()
    await retired(page, 'a')
  })

  for (const ingress of ['own', 'normalize'] as const) {
    test(`G05 ${ingress}: connected DOM is captured before destructive publication`, async ({
      page,
    }, info) => {
      await page.getByTestId('geometry-detail').selectOption('expanded')
      await page.getByTestId('geometry-text').check()
      await press(page, 'geometry-configure')
      await press(page, 'geometry-work')
      await settleTableLayout(page)
      const observed = await watch(page)
      await press(page, `geometry-${ingress}`)
      await retired(page, 'a')
      if (ingress === 'normalize')
        await expect(page.getByTestId('geometry-state')).toHaveAttribute(
          'data-sync',
          'true',
        )
      await evidence(observed, page, info, ingress)
    })
  }

  test('G05 own-create key reuse replaces the old occurrence and ignores its late work completion', async ({
    page,
  }, info) => {
    await press(page, 'geometry-work')
    await page.getByTestId('geometry-detail').selectOption('expanded')
    await press(page, 'geometry-configure')
    await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
    // Own placement is allowed movement. Its outcome has a fresh baseline.
    await press(page, 'geometry-reuse')
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
      'Replacement a',
    )
    await expect(page.getByTestId('geometry-detail-a')).toHaveCount(0)
    await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
    await settleTableLayout(page)
    const observed = await watch(page)
    await press(page, 'geometry-complete')
    await observed.check()
    await press(page, 'geometry-own')
    await retired(page, 'a')
    await evidence(observed, page, info, 'reuse-new-occurrence')
  })

  // FIXME(F-048, 28.10.2026): G09 exceeds its 90-second test timeout · чужой · попытка 2 · HIL-1825
  test.fixme('G09 all 18 freshness × pending × detail combinations preserve marks and content tracks', async ({
    page,
  }, info) => {
    // Eighteen independent configurations, with explicit detail expansion
    // before each baseline; opening detail is a permitted user movement.
    test.slow()
    for (const edge of ['start', 'end']) {
      await page.getByTestId('geometry-edge').selectOption(edge)
      for (const detail of ['absent', 'collapsed', 'expanded']) {
        for (const stale of [false, true]) {
          for (const pending of ['none', 'move', 'remove']) {
            await press(page, 'geometry-reset')
            await page.getByTestId('geometry-detail').selectOption(detail)
            await press(page, 'geometry-configure')
            await settleTableLayout(page)
            const observed = await watch(page)
            await page.getByTestId('geometry-stale').setChecked(stale)
            await page.getByTestId('geometry-pending').selectOption(pending)
            await press(page, 'geometry-configure')
            if (pending !== 'none')
              await expect(page.getByTestId('geometry-state')).toContainText(
                `a:${pending}`,
              )
            await shownByTestId(page, 'hilos-table-select-a').check()
            if (pending === 'none') await press(page, 'geometry-badge')
            await expect(
              shownByTestId(page, 'hilos-table-stale-row-a'),
            ).toHaveCount(stale ? 1 : 0)
            await expect(
              page.getByTestId('hilos-table-stale-column-name'),
            ).toHaveCount(stale ? 1 : 0)
            for (const kind of ['move', 'remove']) {
              await expect(
                shownByTestId(page, `hilos-table-pending-${kind}-a`),
              ).toHaveCount(pending === kind ? 1 : 0)
            }
            if (detail === 'absent') {
              await expect(
                page.getByTestId('hilos-table-expand-a'),
              ).toHaveCount(0)
            } else {
              await expect(
                shownByTestId(page, 'hilos-table-expand-a'),
              ).toHaveAttribute('aria-expanded', String(detail === 'expanded'))
            }
            await expectInertTableHolders(expect, page)
            await shownByTestId(
              page,
              'geometry-badge-a',
            ).scrollIntoViewIfNeeded()
            await fitsInScrollport(shownByTestId(page, 'geometry-badge-a'))
            await shownByTestId(
              page,
              'geometry-action-a',
            ).scrollIntoViewIfNeeded()
            await fitsInScrollport(shownByTestId(page, 'geometry-action-a'))
            await expectTableCardContentFits(expect, page)
            if (pending === 'remove') {
              await press(page, 'geometry-apply')
              await retired(page, 'a')
            }
            await evidence(
              observed,
              page,
              info,
              `${edge}-${detail}-${stale}-${pending}`,
            )
          }
        }
      }
    }
  })

  for (const timing of [
    'before-remove',
    'before-apply',
    'after-apply',
    'positive',
  ]) {
    test(`G06/G07 ${timing}: last membership retains slots, then tail append`, async ({
      page,
    }, info) => {
      const state = page.getByTestId('geometry-state')
      await shownByTestId(page, 'hilos-table-select-a').check()
      await press(page, 'geometry-set-work')
      const requests = await state.getAttribute('data-requests')
      if (timing === 'before-remove') await press(page, 'geometry-count-zero')
      if (timing === 'positive') await press(page, 'geometry-count-positive')
      const observed = await watch(page)
      for (const key of ['a', 'b', 'c', 'd', 'e', 'f', 'g']) {
        await page.getByTestId('geometry-target').selectOption(key)
        await press(page, 'geometry-remove')
      }
      if (timing === 'before-apply') await press(page, 'geometry-count-zero')
      // Count zero must not bypass the pending gate or strip pending values.
      await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
        'Record a',
      )
      await expect(state).toContainText('g:remove')
      await press(page, 'geometry-apply')
      if (timing === 'after-apply') await press(page, 'geometry-count-zero')
      for (const key of ['a', 'b', 'c', 'd', 'e', 'f', 'g'])
        await retired(page, key)
      await expect(state).toHaveAttribute(
        'data-count',
        timing === 'positive' ? '30' : '0',
      )
      await expect(state).toHaveAttribute('data-range', '0:0')
      await expect(state).toHaveAttribute('data-body', 'rows')
      await expect(state).toHaveAttribute('data-selected', 'null')
      await expect(
        page.getByTestId('hilos-table-select-page'),
      ).not.toBeChecked()
      await expect(state).toHaveAttribute('data-requests', requests!)
      await expect(state).toHaveAttribute(
        'data-set-work',
        'set-table:set-bulk:finished',
      )
      await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
      await expect(page.getByTestId('hilos-table-empty')).toHaveCount(0)
      await evidence(observed, page, info, `zero-${timing}`)
      const retained = await watch(page)
      await page.getByTestId('geometry-target').selectOption('h')
      await press(page, 'geometry-append')
      await expectTableRowOrder(expect, page, [
        'a',
        'b',
        'c',
        'd',
        'e',
        'f',
        'g',
        'h',
      ])
      await expect(state).toHaveAttribute('data-range', '1:1')
      await expect(state).toHaveAttribute(
        'data-count',
        timing === 'positive' ? '31' : '1',
      )
      await evidence(retained, page, info, `append-${timing}`)
    })
  }

  test('G07 mixed append and announcement keep slots; Apply never fetches arrivals; Show replaces', async ({
    page,
  }, info) => {
    await press(page, 'geometry-own')
    await retired(page, 'a')
    const retained = await watch(page)
    await page.getByTestId('geometry-target').selectOption('h')
    await press(page, 'geometry-append')
    await expectTableRowOrder(expect, page, [
      'a',
      'b',
      'c',
      'd',
      'e',
      'f',
      'g',
      'h',
    ])
    const state = page.getByTestId('geometry-state')
    await expect(state).toHaveAttribute('data-range', '1:7')
    const requests = await state.getAttribute('data-requests')
    const room = await watchHeight(page.getByTestId('hilos-table-live-slot'))
    await press(page, 'geometry-announce')
    await expect(page.getByTestId('hilos-table-announce')).toBeVisible()
    await room.unchanged()
    await expect(page.getByTestId('geometry-name-j')).toHaveCount(0)
    await page.getByTestId('geometry-target').selectOption('b')
    await press(page, 'geometry-remove')
    await press(page, 'geometry-apply')
    await retired(page, 'b')
    await expect(state).toHaveAttribute('data-requests', requests!)
    await expect(page.getByTestId('geometry-name-j')).toHaveCount(0)
    await evidence(retained, page, info, 'mixed-arrival')
    await press(page, 'hilos-table-announce-show')
    await expect(state).toHaveAttribute(
      'data-requests',
      String(Number(requests) + 1),
    )
    await retiredData(page, 'a')
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Fresh a')
  })

  for (const direction of ['after', 'before'] as const) {
    test(`G07/G10 own-create ${direction} edge and reused key have authoritative placement`, async ({
      page,
    }, info) => {
      await page.getByTestId('geometry-snapshot').selectOption('capacity')
      await press(page, 'geometry-deliver')
      if (direction === 'before') {
        await page.getByTestId('geometry-navigation').selectOption('backward')
        await press(page, 'geometry-window')
        await press(page, 'geometry-deliver')
      }
      await press(page, 'geometry-own')
      await retired(page, 'a')
      await page.getByTestId('geometry-target').selectOption('d')
      await press(page, 'geometry-append')
      // Three live members, plus an unrelated retained slot, already fill the page.
      await page
        .getByTestId('geometry-target')
        .selectOption(direction === 'after' ? 'd' : 'b')
      await shownByTestId(
        page,
        `hilos-table-select-${direction === 'after' ? 'd' : 'b'}`,
      ).check()
      await page.getByTestId('geometry-detail').selectOption('expanded')
      await press(page, 'geometry-configure')
      await press(page, 'geometry-work')
      await page.getByTestId('geometry-target').selectOption('h')
      await press(page, 'geometry-create')
      const expected =
        direction === 'after' ? ['a', 'h', 'b', 'c'] : ['a', 'h', 'c', 'd']
      await expectTableRowOrder(expect, page, expected)
      await retired(page, 'a')
      const evicted = direction === 'after' ? 'd' : 'b'
      for (const prefix of [
        'geometry-name-',
        'geometry-work-',
        'geometry-detail-',
      ])
        await expect(page.getByTestId(`${prefix}${evicted}`)).toHaveCount(0)
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-selected',
        'null',
      )
      const identities = await page
        .getByTestId('geometry-state')
        .getAttribute('data-identities')
      await page.getByTestId('geometry-target').selectOption('a')
      await press(page, 'geometry-create')
      await expect(shownByTestId(page, 'geometry-name-a')).toBeVisible()
      await expect(page.getByTestId('geometry-state')).not.toHaveAttribute(
        'data-identities',
        identities!,
      )
      await expect(page.getByTestId('geometry-detail-a')).toHaveCount(0)
      await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
      await info.attach(`own-placement-${direction}`, {
        body: await page.getByTestId('hilos-viewport-table').screenshot(),
        contentType: 'image/png',
      })
    })
  }

  test('G10 foreign Apply move, own edit and own move preserve unrelated departure and live room', async ({
    page,
  }, info) => {
    await press(page, 'geometry-own')
    await retired(page, 'a')
    const allocation = await observeAllocation(page, 'a', projection)
    const room = await watchHeight(page.getByTestId('hilos-table-live-slot'))
    await page.getByTestId('geometry-target').selectOption('d')
    await setNumber(page, 'geometry-position', 1)
    await press(page, 'geometry-move')
    await expectTableRowOrder(expect, page, ['a', 'b', 'c', 'd', 'e', 'f', 'g'])
    await press(page, 'geometry-apply')
    await expectTableRowOrder(expect, page, ['a', 'b', 'd', 'c', 'e', 'f', 'g'])
    await press(page, 'geometry-badge')
    await expect(shownByTestId(page, 'geometry-badge-d')).toHaveText('Paused')
    await setNumber(page, 'geometry-position', 4)
    await press(page, 'geometry-own-move')
    await expectTableRowOrder(expect, page, ['a', 'b', 'c', 'e', 'f', 'd', 'g'])
    await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
    await room.unchanged()
    await retired(page, 'a')
    await evidence(allocation, page, info, 'allowed-movement')
  })

  for (const navigation of [
    'search',
    'filter',
    'order',
    'page',
    'show',
    'refresh',
  ]) {
    test(`G08 ${navigation} retires the old window before its delayed snapshot`, async ({
      page,
    }, info) => {
      const oldAllocation = await observeAllocation(page, 'a', projection)
      await press(page, 'geometry-own')
      await retired(page, 'a')
      const state = page.getByTestId('geometry-state')
      const identities = await state.getAttribute('data-identities')
      await press(page, 'geometry-count-positive')
      await page.getByTestId('geometry-navigation').selectOption(navigation)
      await press(page, 'geometry-window')
      await retiredData(page, 'a')
      await expect(state).toHaveAttribute('data-requests', '1')
      const descriptor = JSON.parse(
        (await state.getAttribute('data-descriptor'))!,
      )
      if (navigation === 'search')
        expect(descriptor.filter).toEqual({ search: 'needle' })
      if (navigation === 'filter')
        expect(descriptor.filter).toEqual({ status: 'paused' })
      if (navigation === 'order')
        expect(descriptor.sort).toEqual([{ field: 'name', direction: 'desc' }])
      if (navigation === 'page') expect(descriptor.pageIndex).toBe(1)
      await settleTableLayout(page)
      if (navigation !== 'refresh')
        await expect(state).toHaveAttribute('data-body', 'loading')
      await retiredData(page, 'a')
      await press(page, 'geometry-deliver')
      await expect(state).not.toHaveAttribute('data-identities', identities!)
      await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
      await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Fresh a')
      await settleTableLayout(page)
      await oldAllocation.changed('extent', 'shrank')
      await oldAllocation.stop()
      await info.attach(`retired-window-${navigation}`, {
        body: await oldAllocation.evidence(),
        contentType: 'application/json',
      })
      await press(page, 'geometry-own')
      await retired(page, 'a')
      const observed = await watch(page)
      await evidence(observed, page, info, `new-window-${navigation}`)
      await page.getByTestId('geometry-snapshot').selectOption('empty')
      await press(page, 'geometry-deliver')
      await expect(state).toHaveAttribute(
        'data-body',
        navigation === 'search' || navigation === 'filter'
          ? 'empty_filtered'
          : 'empty',
      )
      await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
    })
  }

  test('G08/B04 reconnect, refusal, controller replacement and actual native cleanup', async ({
    page,
  }, info) => {
    // Native instrumentation is installed before the next real fixture mount.
    await watchTableNativeLifecycle(page)
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await settleTableLayout(page)
    // Three geometry listeners plus five value wrappers per record (three
    // wide cells, a card title and a card field), each with its resize listener.
    const initial = await expectTableNativeLifecycle(expect, page, true, 38)
    await press(page, 'geometry-own')
    await press(page, 'geometry-churn')
    await expect(page.getByTestId('geometry-state')).toContainText(
      'cycle-23:departed',
    )
    const churned = (await page.getByTestId('geometry-state').textContent())!
    expect(churned.split(',')).toHaveLength(31)
    expect(churned).toContain('cycle-0:departed')
    await expectTableNativeLifecycle(expect, page, true, 33)
    await press(page, 'geometry-reconnect')
    await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
    await settleTableLayout(page)
    expect(await expectTableNativeLifecycle(expect, page, true, 38)).toBe(
      initial,
    )
    await press(page, 'geometry-refuse')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'unavailable',
    )
    await expect(page.getByTestId('geometry-name-a')).toHaveCount(0)
    await press(page, 'geometry-deliver')
    await press(page, 'geometry-replace')
    await press(page, 'geometry-old')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'rows',
    )
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Record a')
    await settleTableLayout(page)
    expect(await expectTableNativeLifecycle(expect, page, true, 38)).toBe(
      initial,
    )
    for (let cycle = 0; cycle < 3; cycle++) {
      await press(page, 'geometry-unmount')
      await settleTableLayout(page)
      await expectTableNativeLifecycle(expect, page, false)
      await press(page, 'geometry-mount')
      await settleTableLayout(page)
      expect(await expectTableNativeLifecycle(expect, page, true, 38)).toBe(
        initial,
      )
    }
    await info.attach('native-lifetime', {
      body: initial,
      contentType: 'application/json',
    })
  })

  test.describe('native zoom before capture', () => {
    // DPR2 is a cross-check: the nested CSS zoom, independently, is 1.5.
    test.use({ deviceScaleFactor: 2 })
    for (const timing of ['mount', 'live'] as const) {
      for (const ingress of ['foreign', 'own'] as const) {
        test(`B02 ${timing} ${ingress}: complete connected capture under effective ancestor zoom`, async ({
          page,
        }, info) => {
          await page.getByTestId('geometry-detail').selectOption('expanded')
          await page.getByTestId('geometry-text').check()
          await press(page, 'geometry-configure')
          await press(page, 'geometry-work')
          await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
          await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
          await settleTableLayout(page)
          await info.attach('native-before-zoom', {
            body: await expectTableLayoutZoom(
              expect,
              page,
              'geometry-name-a',
              1,
              2,
            ),
            contentType: 'application/json',
          })
          if (timing === 'mount') {
            await press(page, 'geometry-unmount')
            await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(
              0,
            )
          }
          await setTableEnvironment(page, {
            kind: 'zoom',
            factor: 1.25,
            ancestorFactor: 1.2,
          })
          // A new public view registers only after zoom is already active.
          if (timing === 'mount') await press(page, 'geometry-mount')
          await settleTableLayout(page)
          await info.attach('native-at-connected-capture', {
            body: await expectTableLayoutZoom(
              expect,
              page,
              'geometry-name-a',
              1.5,
              2,
            ),
            contentType: 'application/json',
          })
          await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
          await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
          await info.attach('zoomed-connected-layout', {
            body: await page.getByTestId('hilos-viewport-table').screenshot(),
            contentType: 'image/png',
          })
          const bounds = await watch(page)
          const allocation = await observeAllocation(page, 'a', projection)
          if (ingress === 'foreign') {
            await press(page, 'geometry-remove')
            await expect(page.getByTestId('geometry-state')).toContainText(
              'a:remove',
            )
            // The work payload disappears early, but its numeric room and
            // gap must survive pending state and be counted once at Apply.
            await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
            await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
            await bounds.check()
            await allocation.check()
            await press(page, 'geometry-apply')
          } else {
            await press(page, 'geometry-own')
          }
          await retired(page, 'a')
          await bounds.check()
          await allocation.check()
          await press(page, 'geometry-complete')
          await retired(page, 'a')
          await evidence(bounds, page, info, `zoom-${timing}-${ingress}`)
          await evidence(
            allocation,
            page,
            info,
            `zoom-${timing}-${ingress}-allocation`,
          )
        })
      }
    }
  })

  test('B02 captured allocation survives container, root font, late font, spacing and layout zoom', async ({
    page,
  }, info) => {
    await page.getByTestId('geometry-detail').selectOption('expanded')
    await page.getByTestId('geometry-text').check()
    await press(page, 'geometry-configure')
    const retained = await observeAllocation(page, 'a', projection)
    await press(page, 'geometry-own')
    await retired(page, 'a')
    await setTableEnvironment(page, { kind: 'container', narrow: true })
    await settleTableLayout(page)
    await retained.changed('width')
    if (projection === 'wide') await retained.changed('tracks')
    await retained.check()
    await setTableEnvironment(page, { kind: 'font', size: 24 })
    await settleTableLayout(page)
    await retained.changed('rootFont')
    await retained.check()
    await info.attach('late-font-condition', {
      body: await loadLateTableFont(
        expect,
        page,
        'geometry-body',
        'geometry-name-d',
      ),
      contentType: 'application/json',
    })
    await settleTableLayout(page)
    await retained.changed('font')
    await retained.check()
    await setTableEnvironment(page, { kind: 'spacing', enabled: true })
    await settleTableLayout(page)
    await retained.changed('spacing')
    await retained.check()
    await setTableEnvironment(page, { kind: 'zoom', factor: 1.25 })
    await settleTableLayout(page)
    await retained.changed('zoom')
    await retained.check()
    await evidence(retained, page, info, 'reader-layouts')
  })

  test('B03 live survivor values and open detail grow, ordinary completion clears its work', async ({
    page,
  }, info) => {
    await press(page, 'geometry-own')
    const allocation = await observeAllocation(page, 'a', projection)
    await page.getByTestId('geometry-target').selectOption('d')
    await page.getByTestId('geometry-detail').selectOption('expanded')
    await press(page, 'geometry-configure')
    await press(page, 'geometry-work')
    const live = await observeAllocation(page, 'd', projection)
    await press(page, 'geometry-live')
    await expect(shownByTestId(page, 'geometry-name-d')).toHaveText('Updated d')
    await expect(shownByTestId(page, 'geometry-detail-d')).toContainText(
      'Updated detail.',
    )
    await settleTableLayout(page)
    await live.changed('liveHeight', 'grew')
    await evidence(live, page, info, 'survivor-natural-growth')
    const completion = await observeAllocation(page, 'd', projection)
    await press(page, 'geometry-complete')
    await expect(page.getByTestId('geometry-work-d')).toHaveCount(0)
    await settleTableLayout(page)
    await completion.changed('extent', 'shrank')
    await evidence(completion, page, info, 'ordinary-completion')
    await retired(page, 'a')
    await evidence(allocation, page, info, 'unrelated-departure')
    await press(page, 'geometry-own')
    await retired(page, 'd')
  })

  test('B05 modal returns to its marked replacement and to search after removal', async ({
    page,
  }, info) => {
    const trigger = shownByTestId(page, 'geometry-action-a')
    const focus = await watchTableModalFocus(trigger)
    await trigger.focus()
    await trigger.press('Enter')
    const modal = page.getByTestId('modal')
    await expect(modal).toBeFocused()
    await expect(modal).toHaveAccessibleName('Inspect geometry record')
    const opened = await focus.read()
    expect(opened.openerConnected).toBe(true)
    await page
      .getByTestId('geometry-deliver')
      .evaluate((button: HTMLButtonElement) => button.click())
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Fresh a')
    await settleTableLayout(page)
    const replaced = await focus.read()
    expect(replaced.openerConnected).toBe(false)
    expect(replaced.sameTrigger).toBe(false)
    expect(replaced.visibleTriggers).toBe(1)
    expect(replaced.rootConnected).toBe(true)
    const freshTrigger = shownByTestId(page, 'geometry-action-a')
    await expect(freshTrigger).toHaveAttribute(
      'data-hilos-focus-return',
      'inspect-geometry',
    )
    await expect(modal).toBeFocused()
    await modal.press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await page.getByTestId('modal-close').press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await modal.press('Escape')
    await expect(modal).toHaveCount(0)
    await expect(freshTrigger).toBeFocused()
    const returned = await focus.read()
    expect(returned.inDialog).toBe(false)
    expect(returned.openerConnected).toBe(false)
    expect(returned.sameTrigger).toBe(false)
    expect(returned.triggerFocused).toBe(false)
    const removal = await watchTableModalFocus(trigger)
    await trigger.focus()
    await trigger.press('Enter')
    await expect(modal).toBeFocused()
    await page
      .getByTestId('geometry-own')
      .evaluate((button: HTMLButtonElement) => button.click())
    await retired(page, 'a')
    await settleTableLayout(page)
    const removed = await removal.read()
    expect(removed.openerConnected).toBe(false)
    expect(removed.visibleTriggers).toBe(0)
    await expect(modal).toBeFocused()
    await modal.press('Escape')
    await expect(modal).toHaveCount(0)
    await expect(page.getByTestId('hilos-table-search')).toBeFocused()
    await info.attach('modal-occurrence-focus', {
      body: JSON.stringify(
        {
          opened,
          replaced,
          returned,
          removed,
          afterRemoval: await removal.read(),
        },
        null,
        2,
      ),
      contentType: 'application/json',
    })
    await info.attach('modal-occurrence-return', {
      body: await page.getByTestId('hilos-viewport-table').screenshot(),
      contentType: 'image/png',
    })
    await removal.dispose()
    await focus.dispose()
  })

  for (const focus of ['action', 'disclosure', 'detail', 'other', 'root']) {
    test(`B05 ${focus} focus is restored only when its connected control departs`, async ({
      page,
    }, info) => {
      if (focus === 'root') await press(page, 'geometry-no-search')
      if (focus === 'detail') {
        await page.getByTestId('geometry-detail').selectOption('expanded')
        await press(page, 'geometry-configure')
      }
      const id =
        focus === 'disclosure'
          ? 'hilos-table-expand-a'
          : focus === 'detail'
            ? 'geometry-detail-action-a'
            : focus === 'other'
              ? 'geometry-action-d'
              : 'geometry-action-a'
      const control = shownByTestId(page, id)
      if (focus === 'detail') {
        const controls = await page
          .getByTestId('hilos-table-expand-a')
          .evaluateAll((nodes) =>
            nodes.map((node) => node.getAttribute('aria-controls')),
          )
        expect(controls).toHaveLength(2)
        expect(controls[0]).not.toBe(controls[1])
      }
      await control.focus()
      await expect(control).toBeFocused()
      await expect(control).toHaveAccessibleName(
        focus === 'detail'
          ? 'Detail action'
          : focus === 'disclosure'
            ? 'Show details'
            : 'Inspect',
      )
      if (focus === 'disclosure') {
        await control.press('Enter')
        await expect(control).toHaveAttribute('aria-expanded', 'true')
      }
      // Deliver synthetic ingress without clicking a different focus target.
      await page
        .getByTestId('geometry-own')
        .evaluate((button: HTMLButtonElement) => button.click())
      await retired(page, 'a')
      await settleTableLayout(page)
      await expect(
        focus === 'other'
          ? control
          : page.getByTestId(
              focus === 'root' ? 'hilos-viewport-table' : 'hilos-table-search',
            ),
      ).toBeFocused()
      await expect(
        page
          .getByTestId('hilos-viewport-table')
          .getByRole(projection === 'wide' ? 'table' : 'list', {
            name: 'Geometry records',
            exact: true,
          }),
      ).toHaveCount(1)
      await expect(
        page
          .getByTestId('hilos-viewport-table')
          .getByRole(projection === 'wide' ? 'list' : 'table', {
            name: 'Geometry records',
            exact: true,
          }),
      ).toHaveCount(0)
      await expectInertTableHolders(expect, page)
      await setTableEnvironment(page, { kind: 'spacing', enabled: true })
      await setTableEnvironment(page, { kind: 'zoom', factor: 1.25 })
      await settleTableLayout(page)
      await expectTableCardContentFits(expect, page)
      await retired(page, 'a')
      await info.attach(`focus-${focus}`, {
        body: await page.getByTestId('hilos-viewport-table').screenshot(),
        contentType: 'image/png',
      })
    })
  }
})

test.describe('Tiles: public SDK at desktop width with synthetic ingress', () => {
  test.beforeEach(async ({ page }) => {
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await page.getByTestId('geometry-tiles').check()
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await expect(page.getByTestId('hilos-table-view-table')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  test.afterEach(async ({ page }, info) => {
    for (const [index, observation] of (
      observations.get(page) ?? []
    ).entries()) {
      await observation.stop()
      if (info.status !== info.expectedStatus)
        await info.attach(`tiles-failure-${index}`, {
          body: await observation.evidence(),
          contentType: 'application/json',
        })
    }
    observations.delete(page)
  })

  async function tiles(page: Page): Promise<void> {
    await press(page, 'hilos-table-view-tiles')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-view',
      'tiles',
    )
    await settleTableLayout(page)
  }

  async function tileGone(page: Page, key: string): Promise<void> {
    await expectTableTileDeparture(expect, page, key, 'Removed', [
      `geometry-name-${key}`,
      `geometry-picture-${key}`,
      `geometry-text-${key}`,
      `geometry-badge-${key}`,
      `geometry-action-${key}`,
      `geometry-detail-${key}`,
      `geometry-work-${key}`,
    ])
  }

  async function watchTiles(page: Page) {
    const watch = await watchTableGeometry(expect, page, {
      bodyId: 'geometry-body',
      projection: 'tiles',
      rowKeys: ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
      lastKey: 'g',
    })
    observations.set(page, [...(observations.get(page) ?? []), watch])
    return watch
  }

  test('T01 native view buttons, list semantics, decoded pictures and all orders without headers', async ({
    page,
  }, info) => {
    const group = page.getByTestId('hilos-table-view')
    await expect(group).toHaveRole('group')
    await expect(group).toHaveAccessibleName('Table view')
    const table = page.getByTestId('hilos-table-view-table')
    const tile = page.getByTestId('hilos-table-view-tiles')
    for (const button of [table, tile]) {
      await expect(button).toHaveJSProperty('tagName', 'BUTTON')
      await expect(button).toHaveAttribute('type', 'button')
    }
    await expect(
      page.getByTestId('hilos-table-row-a').getByTestId('geometry-picture-a'),
    ).toBeVisible()
    const url = page.url()
    await table.focus()
    await table.press('Tab')
    await expect(tile).toBeFocused()
    await tile.press('Enter')
    await expect(tile).toHaveAttribute('aria-pressed', 'true')
    await expect(table).toHaveAttribute('aria-pressed', 'false')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-requests',
      '0',
    )
    expect(page.url()).toBe(url)
    const root = page.getByTestId('hilos-viewport-table')
    await expect(root.getByRole('table')).toHaveCount(0)
    const list = root.getByRole('list', {
      name: 'Geometry records',
      exact: true,
    })
    await expect(list).toHaveCount(1)
    await expect(list.getByRole('listitem')).toHaveCount(7)
    await info.attach('tiles-picture-frames', {
      body: await expectTableTilePictures(expect, page, ['a', 'b', 'c'], ['d']),
      contentType: 'application/json',
    })
    const menu = page.getByTestId('hilos-table-order')
    const toggle = menu.getByTestId('hilos-dropdown-toggle')
    await toggle.focus()
    await toggle.press('ArrowDown')
    const orders = [
      ['opening', 'Name ↑'],
      ['name-desc', 'Name ↓'],
      ['badge-asc', 'Status ↑'],
      ['badge-desc', 'Status ↓'],
      ['name-status', 'Name ↑, then Status ↓'],
      ['name-status-mirror', 'Name ↓, then Status ↑'],
      ['status-name', 'Status ↑, then Name ↓'],
      ['status-name-mirror', 'Status ↓, then Name ↑'],
    ] as const
    await expect(menu.getByRole('option')).toHaveCount(8)
    for (const [key, label] of orders) {
      const option = menu.getByTestId(`hilos-table-order-${key}`)
      await expect(option).toBeVisible()
      await expect(option).toHaveAccessibleName(label)
    }
    await expect(menu.getByTestId('hilos-table-order-name-asc')).toHaveCount(0)
    const opening = menu.getByTestId('hilos-table-order-opening')
    await expect(opening).toBeFocused()
    await opening.press('End')
    await expect(
      menu.getByTestId('hilos-table-order-status-name-mirror'),
    ).toBeFocused()
    await menu.getByTestId('hilos-table-order-status-name-mirror').press('Home')
    await expect(opening).toBeFocused()
    await opening.press('ArrowDown')
    await expect(menu.getByTestId('hilos-table-order-name-desc')).toBeFocused()
    await menu.getByTestId('hilos-table-order-name-desc').press('Enter')
    await expect(toggle).toBeFocused()
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'loading',
    )
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await expect(toggle).toContainText('Name ↓')
  })

  test('T02 switching preserves the window, selection, pending, work, detail and text intent without a send', async ({
    page,
  }) => {
    await tiles(page)
    await page.getByTestId('geometry-height-mode').selectOption('lines')
    await press(page, 'geometry-reading')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-view',
      'tiles',
    )
    await page.getByTestId('geometry-snapshot').selectOption('initial')
    await typeInto(page.getByTestId('hilos-table-search'), 'needle')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'loading',
    )
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await page.getByTestId('hilos-table-filter-status').check()
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'loading',
    )
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    const order = page.getByTestId('hilos-table-order')
    await clickSubmit(expect, order.getByTestId('hilos-dropdown-toggle'))
    await clickSubmit(expect, order.getByTestId('hilos-table-order-name-desc'))
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'loading',
    )
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await page.getByTestId('geometry-snapshot').selectOption('capacity')
    await press(page, 'geometry-deliver')
    await page.getByTestId('geometry-navigation').selectOption('page')
    await press(page, 'geometry-window')
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-body',
      'loading',
    )
    await press(page, 'geometry-deliver')
    await expect(page.getByTestId('geometry-state')).toContainText('c:live')
    await shownByTestId(page, 'hilos-table-select-a').check()
    await shownByTestId(page, 'hilos-table-expand-a').click()
    const textToggle = shownByTestId(
      page
        .getByTestId('hilos-table-tile-a')
        .or(page.getByTestId('hilos-table-row-a')),
      'hilos-table-text-toggle',
    )
    await textToggle.click()
    await page.getByTestId('geometry-pending').selectOption('move')
    await page.getByTestId('geometry-detail').selectOption('expanded')
    await press(page, 'geometry-configure')
    await press(page, 'geometry-work')
    const state = page.getByTestId('geometry-state')
    await expect(state).toContainText('a:move')
    await expect(state).toHaveAttribute('data-expanded', '["a"]')
    // Reading intent belongs to the current occurrence.
    const identities = (await state.getAttribute('data-identities'))!
    const occurrence = Number(identities.match(/^a:(\d+),/)![1])
    expect(occurrence).toBeGreaterThan(0)
    await expect(state).toHaveAttribute(
      'data-text-intent',
      JSON.stringify([[occurrence, ['paragraph']]]),
    )
    const attributes = [
      'data-identities',
      'data-selected',
      'data-expanded',
      'data-text-intent',
      'data-descriptor',
      'data-requests',
      'data-range',
      'data-count',
      'data-body',
    ]
    const before = await Promise.all(
      attributes.map((name) => state.getAttribute(name)),
    )
    const descriptor = JSON.parse(before[4]!)
    expect(descriptor.filter).toEqual({ search: 'needle', status: 'paused' })
    expect(descriptor.sort).toEqual([{ field: 'name', direction: 'desc' }])
    expect(descriptor.pageIndex).toBe(1)
    const url = page.url()
    for (const view of ['table', 'tiles']) {
      await press(page, `hilos-table-view-${view}`)
      await expect(state).toHaveAttribute('data-view', view)
      for (const [index, name] of attributes.entries())
        await expect(state).toHaveAttribute(name, before[index]!)
      await expect(state).toContainText('a:move')
      await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
      await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
      await expect(shownByTestId(page, 'hilos-table-select-a')).toBeChecked()
      await expect(textToggle).toHaveAttribute('aria-expanded', 'true')
      expect(page.url()).toBe(url)
    }
  })

  test('T03 preference restores on a fresh mount and F5 at the same URL', async ({
    page,
  }) => {
    await tiles(page)
    const stored = JSON.stringify({
      version: 1,
      views: { 'geometry-records': 'tiles' },
    })
    expect(
      await page.evaluate(
        (key) => localStorage.getItem(key),
        TABLE_VIEW_STORAGE_KEY,
      ),
    ).toBe(stored)
    await press(page, 'geometry-unmount')
    await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
    await press(page, 'geometry-mount')
    await expect(page.getByTestId('hilos-table-view-tiles')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    await press(page, 'geometry-replace')
    await expect(page.getByTestId('hilos-table-view-tiles')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    const url = page.url()
    await page.reload()
    await expect(page.getByTestId('geometry-fixture')).toBeVisible()
    // Re-declare the optional fixture capability; the production binding restores it.
    await page.getByTestId('geometry-tiles').check()
    await expect(page.getByTestId('hilos-table-view-tiles')).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    await expect(page.getByTestId('hilos-table-tiles')).toBeVisible()
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-requests',
      '0',
    )
    expect(page.url()).toBe(url)
    expect(
      await page.evaluate(
        (key) => localStorage.getItem(key),
        TABLE_VIEW_STORAGE_KEY,
      ),
    ).toBe(stored)
  })

  for (const ingress of ['own', 'foreign'] as const) {
    test(`T04 ${ingress}: full image/detail/work tile retains its occurrence and grid footprint on every mutation/frame`, async ({
      page,
    }, info) => {
      await tiles(page)
      await page.getByTestId('geometry-detail').selectOption('expanded')
      await page.getByTestId('geometry-text').check()
      await press(page, 'geometry-configure')
      await press(page, 'geometry-work')
      await expect(shownByTestId(page, 'geometry-detail-a')).toBeVisible()
      await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
      await expectTableTilePictures(expect, page, ['a'], ['d'])
      await settleTableLayout(page)
      const grid = await watchTiles(page)
      const allocation = await observeAllocation(page, 'a', 'tiles')
      const identities = await page
        .getByTestId('geometry-state')
        .getAttribute('data-identities')
      await press(page, ingress === 'own' ? 'geometry-own' : 'geometry-remove')
      if (ingress === 'foreign') {
        await expect(page.getByTestId('geometry-state')).toContainText(
          'a:remove',
        )
        await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
        await grid.check()
        await allocation.check()
        await press(page, 'hilos-table-apply')
      }
      await tileGone(page, 'a')
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-identities',
        identities!,
      )
      await press(page, 'geometry-complete')
      await evidence(allocation, page, info, `tiles-${ingress}-used-allocation`)
      await evidence(grid, page, info, `tiles-${ingress}-grid`)
    })
  }

  test('T05 completed work is released; fresh windows, reuse and stale observer callbacks keep their own geometry', async ({
    page,
  }, info) => {
    const callbacks = await watchTableGeometryCallbacks(page)
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
    await page.getByTestId('geometry-tiles').check()
    await tiles(page)
    await page.getByTestId('geometry-detail').selectOption('expanded')
    await press(page, 'geometry-configure')
    await press(page, 'geometry-work')
    await settleTableLayout(page)
    const working = await observeAllocation(page, 'a', 'tiles')
    await press(page, 'geometry-complete')
    await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
    await settleTableLayout(page)
    await working.changed('extent', 'shrank')
    const completed = await observeAllocation(page, 'a', 'tiles')
    const old = await callbacks.capture()
    const identities = await page
      .getByTestId('geometry-state')
      .getAttribute('data-identities')
    await press(page, 'geometry-own')
    await tileGone(page, 'a')
    await evidence(completed, page, info, 'tiles-finished-work-departure')
    await press(page, 'geometry-deliver')
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Fresh a')
    await expect(page.getByTestId('geometry-state')).not.toHaveAttribute(
      'data-identities',
      identities!,
    )
    await settleTableLayout(page)
    await working.changed('extent', 'shrank')
    await evidence(working, page, info, 'tiles-fresh-window-release')
    const fresh = await watchTiles(page)
    await old.replay(expect)
    await evidence(fresh, page, info, 'tiles-old-window-callbacks')
    const beforeReuse = await page
      .getByTestId('geometry-state')
      .getAttribute('data-identities')
    const previous = await callbacks.capture()
    await press(page, 'geometry-reuse')
    await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
      'Replacement a',
    )
    await expect(page.getByTestId('geometry-state')).not.toHaveAttribute(
      'data-identities',
      beforeReuse!,
    )
    await settleTableLayout(page)
    const reused = await watchTiles(page)
    await previous.replay(expect)
    await evidence(reused, page, info, 'tiles-old-occurrence-callbacks')
    await old.dispose()
    await previous.dispose()
    const own = await observeAllocation(page, 'a', 'tiles')
    await press(page, 'geometry-own')
    await tileGone(page, 'a')
    await evidence(own, page, info, 'tiles-reused-own-allocation')
  })

  for (const outcome of [
    'replacement',
    'view',
    'removed',
    'ambiguous',
    'root',
  ] as const) {
    test(`T06 marked modal focus: ${outcome}`, async ({ page }, info) => {
      await tiles(page)
      if (outcome === 'root') {
        await press(page, 'geometry-no-search')
        await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
      }
      const trigger = shownByTestId(page, 'geometry-action-a')
      const focus = await watchTableModalFocus(trigger)
      await trigger.focus()
      await trigger.press('Enter')
      const modal = page.getByTestId('modal')
      await expect(modal).toBeFocused()
      await expect(modal).toHaveAccessibleName('Inspect geometry record')
      const operation =
        outcome === 'view'
          ? 'hilos-table-view-table'
          : outcome === 'removed' || outcome === 'root'
            ? 'geometry-own'
            : 'geometry-deliver'
      await shownByTestId(page, operation).evaluate(
        (button: HTMLButtonElement) => button.click(),
      )
      if (outcome === 'ambiguous')
        await page
          .getByTestId('geometry-ambiguous-focus')
          .evaluate((input: HTMLInputElement) => input.click())
      if (outcome === 'removed' || outcome === 'root') await tileGone(page, 'a')
      else if (outcome === 'view')
        await expect(page.getByTestId('geometry-state')).toHaveAttribute(
          'data-view',
          'table',
        )
      else
        await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
          'Fresh a',
        )
      await settleTableLayout(page)
      await expect(modal).toBeFocused()
      await modal.press('Tab')
      await expect(page.getByTestId('modal-close')).toBeFocused()
      await page.getByTestId('modal-close').press('Tab')
      await expect(page.getByTestId('modal-close')).toBeFocused()
      await modal.press('Escape')
      await expect(modal).toHaveCount(0)
      const target =
        outcome === 'replacement'
          ? shownByTestId(page, 'geometry-action-a')
          : outcome === 'root'
            ? page.getByTestId('hilos-viewport-table')
            : page.getByTestId('hilos-table-search')
      await expect(target).toBeVisible()
      await expect(target).toBeFocused()
      if (outcome === 'replacement') {
        await expect(target).toHaveAttribute(
          'data-hilos-focus-return',
          'inspect-geometry',
        )
        expect((await focus.read()).openerConnected).toBe(false)
      }
      await info.attach(`tiles-modal-${outcome}`, {
        body: JSON.stringify(await focus.history(), null, 2),
        contentType: 'application/json',
      })
      await focus.dispose()
    })
  }
})

test.describe('public SDK text reading with synthetic ingress', () => {
  test.beforeEach(async ({ page }) => {
    await openTableFixture(
      expect,
      page,
      '/__table_geometry/index.html',
      'geometry-fixture',
    )
  })

  async function reading(
    page: Page,
    policy: TableTextPolicy | { readonly mode: 'default' },
    content: 'text' | 'parts' = 'text',
  ): Promise<void> {
    await page.getByTestId('geometry-height-mode').selectOption(policy.mode)
    if (policy.mode === 'lines')
      await page
        .getByTestId('geometry-lines')
        .selectOption(String(policy.count))
    if (policy.mode === 'height')
      await page.getByTestId('geometry-rem').selectOption(String(policy.rem))
    await page.getByTestId('geometry-height-content').selectOption(content)
    await press(page, 'geometry-reading')
    await expect(page.getByTestId('geometry-state')).toContainText('g:live')
    await settleTableLayout(page)
  }

  async function textEvidence(
    page: Page,
    info: TestInfo,
    key: string,
    policy: TableTextPolicy,
    expanded = false,
    overflow = false,
  ): Promise<void> {
    await info.attach(`${key}-${policy.mode}-${expanded ? 'open' : 'closed'}`, {
      body: await expectTableTextGeometry(
        expect,
        page,
        key,
        policy,
        expanded,
        overflow,
      ),
      contentType: 'application/json',
    })
  }

  for (const mode of ['default', 'content'] as const) {
    for (const content of ['text', 'parts'] as const) {
      test(`H01 ${mode}/${content}: intrinsic text wraps completely without cap, room or control`, async ({
        page,
      }, info) => {
        await reading(page, { mode }, content)
        for (const key of ['a', 'b', 'c', 'd', 'e'])
          await textEvidence(page, info, key, { mode: 'content' })
        await expectTableIntrinsicRows(expect, page, 'a', 'b')
        await expect(tableTextRegion(page, 'a')).toContainText(
          'LAST READABLE LINE',
        )
        await expect(page.getByTestId('hilos-table-text-toggle')).toHaveCount(0)
      })
    }
  }

  const PRESETS: readonly TableTextPolicy[] = [
    ...[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((count) => ({
      mode: 'lines' as const,
      count,
    })),
    ...[2, 3, 4.5, 6, 9, 12].map((rem) => ({ mode: 'height' as const, rem })),
  ]
  for (const policy of PRESETS) {
    test(`H02 shipped ${JSON.stringify(policy)} reserves short/empty and clips overflowing text`, async ({
      page,
    }, info) => {
      await reading(page, policy)
      await textEvidence(page, info, 'a', policy, false, true)
      for (const key of ['b', 'c']) {
        await textEvidence(page, info, key, policy)
        await expect(tableTextToggle(page, key)).toHaveCount(0)
      }
      await expectTableTextControl(expect, page, 'a', false)
    })
  }

  const BOUNDARIES: readonly TableTextPolicy[] = [
    { mode: 'lines', count: 1 },
    { mode: 'lines', count: 3 },
    { mode: 'lines', count: 12 },
    { mode: 'height', rem: 4.5 },
  ]
  for (const policy of BOUNDARIES) {
    for (const content of ['text', 'parts'] as const) {
      test(`H03/H04 ${JSON.stringify(policy)}/${content}: newline/token reading, independent chrome and constant room`, async ({
        page,
      }, info) => {
        await reading(page, policy, content)
        const room = await watchTableTextRoom(page, 'b')
        const inline = await watchTableTextInline(expect, page, 'b')
        const stable = await watchTableGeometry(expect, page, {
          bodyId: 'geometry-body',
          projection: 'wide',
          rowKeys: ['a', 'b', 'c', ...SURVIVORS, 'g'],
          lastKey: 'g',
        })
        try {
          await page.getByTestId('geometry-target').selectOption('b')
          await press(page, 'geometry-value')
          await expectTableTextControl(expect, page, 'b', false)
          await settleTableLayout(page)
          await room.unchanged()
          await inline.unchanged()
          await stable.check()
        } finally {
          await stable.stop()
          await info.attach('overflow-arrival-bounds', {
            body: await stable.evidence(),
            contentType: 'application/json',
          })
        }
        for (const key of ['a', 'd', 'e']) {
          await textEvidence(page, info, key, policy, false, true)
          const inline = await watchTableTextInline(expect, page, key)
          const control = tableTextToggle(page, key)
          await control.click()
          await expectTableTextControl(expect, page, key, true)
          await settleTableLayout(page)
          await textEvidence(page, info, key, policy, true)
          await inline.unchanged()
          if (key === 'd')
            expect(await tableTextRegion(page, key).textContent()).toBe(
              `${Array.from({ length: 16 }, (_, index) => `Explicit line ${index + 1}`).join('\n')}\nLAST READABLE LINE`,
            )
          if (key === 'e')
            await expect(tableTextRegion(page, key)).toContainText('ENDTOKEN')
          if (content === 'parts')
            await expectTableTextParts(expect, page, key, [
              'geometry-text-badge',
              'geometry-text-link',
              'geometry-text-action',
            ])
          await control.click()
          await expectTableTextControl(expect, page, key, false)
          await settleTableLayout(page)
          await textEvidence(page, info, key, policy, false, true)
          await inline.unchanged()
          await info.attach(`${key}-text-only-inline-footprint`, {
            body: await inline.evidence(),
            contentType: 'application/json',
          })
        }
        await info.attach('overflow-arrival-inline-footprint', {
          body: await inline.evidence(),
          contentType: 'application/json',
        })
        if (content === 'parts') {
          const hidden = page.getByTestId('hilos-table-row-f')
          await expect(hidden.getByTestId('hilos-hidden')).toBeVisible()
          await expect(hidden).not.toContainText('[object Object]')
          await expect(
            hidden.getByTestId('hilos-table-text-toggle'),
          ).toHaveCount(0)
          await expectTableTextParts(expect, page, 'f', [
            'hilos-hidden',
            'geometry-text-badge',
            'geometry-text-link',
            'geometry-text-action',
          ])
        }
        await expect(page.getByTestId('geometry-state')).toHaveAttribute(
          'data-selected',
          'null',
        )
        await expect(page.getByTestId('geometry-state')).toHaveAttribute(
          'data-requests',
          '0',
        )
        await expect(page.getByTestId('geometry-actions')).toHaveText('')
        await expect(page.getByTestId('modal')).toHaveCount(0)
        await expect(page.getByTestId('geometry-detail-a')).toHaveCount(0)
      })
    }
  }

  for (const policy of [{ mode: 'lines', count: 3 }] as const) {
    test(`H02/H06 ${policy.mode}: root rem, text-only lh, width, spacing and late font invalidate actual layout`, async ({
      page,
    }, info) => {
      await reading(page, policy)
      await textEvidence(page, info, 'b', policy)
      await setTableEnvironment(page, { kind: 'font', size: 20 })
      await settleTableLayout(page)
      await textEvidence(page, info, 'b', policy)
      const root = await page
        .locator('html')
        .evaluate((node) => getComputedStyle(node).fontSize)
      expect(root).toBe('20px')
      await setTableEnvironment(page, {
        kind: 'text-font',
        size: 48,
        lineHeight: 2,
      })
      await settleTableLayout(page)
      await expect(tableTextToggle(page, 'b')).toHaveCount(0)
      await textEvidence(page, info, 'b', policy)
      expect(
        await page
          .locator('html')
          .evaluate((node) => getComputedStyle(node).fontSize),
      ).toBe(root)
      await setTableEnvironment(page, {
        kind: 'text-font',
        size: 16,
        lineHeight: 1.5,
      })
      await settleTableLayout(page)
      await expect(tableTextToggle(page, 'b')).toHaveCount(0)
      await setTableEnvironment(page, { kind: 'container', narrow: true })
      await setTableEnvironment(page, { kind: 'spacing', enabled: true })
      await info.attach('late-font-condition', {
        body: await loadLateTableFont(
          expect,
          page,
          'geometry-body',
          'geometry-name-b',
        ),
        contentType: 'application/json',
      })
      await settleTableLayout(page)
      await textEvidence(page, info, 'a', policy, false, true)
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', true)
      await settleTableLayout(page)
      await textEvidence(page, info, 'a', policy, true)
    })
  }

  for (const ingress of ['own', 'foreign', 'immediate'] as const) {
    test(`H06/G03 ${ingress}: expanded reading plus detail/work captured once, then data-free grow-only retention`, async ({
      page,
    }, info) => {
      const policy = { mode: 'lines', count: 3 } as const
      await reading(page, policy, 'parts')
      await page.getByTestId('geometry-detail').selectOption('expanded')
      await press(page, 'geometry-configure')
      await press(page, 'geometry-value')
      await press(page, 'geometry-work')
      const live = await watchTableAllocation(expect, page, 'a', 'wide')
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', true)
      await settleTableLayout(page)
      await live.changed('extent', 'grew')
      await textEvidence(page, info, 'a', policy, true)
      const open = await watchTableAllocation(expect, page, 'a', 'wide')
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', false)
      await settleTableLayout(page)
      await open.changed('extent', 'shrank')
      await open.stop()
      const collapsed = await watchTableAllocation(expect, page, 'a', 'wide')
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', true)
      await settleTableLayout(page)
      await collapsed.changed('extent', 'grew')
      await live.stop()
      await collapsed.stop()
      if (ingress === 'immediate')
        await page.getByTestId('geometry-payload').selectOption('longer')
      const allocation = await watchTableAllocation(expect, page, 'a', 'wide')
      const stable = await watchTableGeometry(expect, page, {
        bodyId: 'geometry-body',
        projection: 'wide',
        rowKeys: SURVIVORS,
        lastKey: 'g',
      })
      try {
        if (ingress === 'foreign') {
          await press(page, 'geometry-remove')
          await expect(page.getByTestId('geometry-state')).toContainText(
            'a:remove',
          )
          await expect(tableTextRegion(page, 'a')).toContainText(
            'LAST READABLE LINE',
          )
          await expectTableTextControl(expect, page, 'a', true)
          await stable.check()
          await allocation.check()
          await press(page, 'geometry-apply')
        } else
          await press(
            page,
            ingress === 'own' ? 'geometry-own' : 'geometry-immediate',
          )
        await retired(page, 'a')
        await expect(page.getByTestId('geometry-state')).toHaveAttribute(
          'data-text-intent',
          '[]',
        )
        if (ingress === 'immediate') await allocation.changed('extent', 'grew')
        else await stable.check()
        await allocation.check()
        await setTableEnvironment(page, { kind: 'font', size: 24 })
        await setTableEnvironment(page, { kind: 'spacing', enabled: true })
        await setTableEnvironment(page, { kind: 'container', narrow: true })
        await settleTableLayout(page)
        await allocation.check()
        await press(page, 'geometry-complete')
        await allocation.check()
        await retired(page, 'a')
      } finally {
        await stable.stop()
        await allocation.stop()
        await info.attach('disclosure-departure-bounds', {
          body: await stable.evidence(),
          contentType: 'application/json',
        })
        await info.attach('disclosure-retained-bounds', {
          body: await allocation.evidence(),
          contentType: 'application/json',
        })
      }
    })
  }

  test('H06/B04 reset/reuse/window/reconnect/refusal retire disclosure intent and stale controls', async ({
    page,
  }) => {
    await reading(page, { mode: 'lines', count: 3 })
    for (const transition of [
      'reuse',
      'reset',
      'deliver',
      'reconnect',
      'window',
      'refuse',
      'replace',
    ]) {
      // A fresh occurrence starts short, then receives a genuinely overflowing value.
      await page.getByTestId('geometry-payload').selectOption('long')
      await press(page, 'geometry-value')
      await expectTableTextControl(expect, page, 'a', false)
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', true)
      const old = await tableTextToggle(page, 'a').elementHandle()
      await press(page, `geometry-${transition}`)
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-text-intent',
        '[]',
      )
      await old!.evaluate((button: HTMLButtonElement) => button.click())
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-text-intent',
        '[]',
      )
      await old!.dispose()
      if (transition === 'window' || transition === 'refuse')
        await press(page, 'geometry-deliver')
      await settleTableLayout(page)
      await expect(tableTextRegion(page, 'a')).not.toHaveClass(/is-open/)
    }
  })

  for (const content of ['text', 'parts'] as const) {
    test(`H06/B04 ${content}: reading observers release on departure, reconnect and unmount`, async ({
      page,
    }, info) => {
      await watchTableNativeLifecycle(page)
      await openTableFixture(
        expect,
        page,
        '/__table_geometry/index.html',
        'geometry-fixture',
      )
      await reading(page, { mode: 'lines', count: 3 }, content)
      // Existing 38 listeners plus three reading listeners per text window in
      // each projection. The parts fixture's hidden f has no reading window.
      const listeners = content === 'text' ? 80 : 74
      const initial = await expectTableNativeLifecycle(
        expect,
        page,
        true,
        listeners,
      )
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', true)
      await press(page, 'geometry-own')
      await retired(page, 'a')
      await settleTableLayout(page)
      await expectTableNativeLifecycle(expect, page, true, listeners - 11)
      await press(page, 'geometry-reconnect')
      await settleTableLayout(page)
      expect(
        await expectTableNativeLifecycle(expect, page, true, listeners),
      ).toBe(initial)
      await press(page, 'geometry-unmount')
      await settleTableLayout(page)
      await expectTableNativeLifecycle(expect, page, false)
      // Real browser invalidations after disposal cannot recreate controls,
      // retained nodes or listeners through an already queued callback.
      await setTableEnvironment(page, { kind: 'font', size: 20 })
      await setTableEnvironment(page, { kind: 'spacing', enabled: true })
      await settleTableLayout(page)
      await expectTableNativeLifecycle(expect, page, false)
      await expect(page.getByTestId('hilos-table-text-toggle')).toHaveCount(0)
      await expect(page.getByTestId('geometry-text-a')).toHaveCount(0)
      await info.attach('reading-native-lifetime', {
        body: initial,
        contentType: 'application/json',
      })
    })
  }
})

test.describe('text calibration regression', () => {
  test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })
  for (const mode of ['content', 'lines'] as const) {
    for (const content of ['text', 'parts'] as const) {
      test(`R08 ${mode}/${content}: long tokens fit the first retained tracks`, async ({
        page,
      }, info) => {
        await openTableFixture(
          expect,
          page,
          '/__table_geometry/index.html',
          'geometry-fixture',
        )
        await page.getByTestId('geometry-height-mode').selectOption(mode)
        await page.getByTestId('geometry-height-content').selectOption(content)
        await press(page, 'geometry-reading')
        await settleTableLayout(page)
        const lines = mode === 'lines' ? 3 : null
        for (const key of ['a', 'd', 'e']) {
          await info.attach(`${key}-collapsed`, {
            body: await expectTableTextTrack(expect, page, key, lines),
            contentType: 'application/json',
          })
        }
        for (const key of ['b', 'c']) {
          await expectTableTextTrack(expect, page, key, lines, false, false)
          await expect(tableTextToggle(page, key)).toHaveCount(0)
        }
        if (mode === 'content') {
          await expect(page.getByTestId('hilos-table-text-toggle')).toHaveCount(
            0,
          )
        } else {
          await expectTableTextControl(expect, page, 'a', false)
          const toggle = tableTextToggle(page, 'a')
          await toggle.focus()
          await toggle.press('Enter')
          await expectTableTextControl(expect, page, 'a', true)
          await settleTableLayout(page)
          await info.attach('expanded', {
            body: await expectTableTextTrack(expect, page, 'a', lines, true),
            contentType: 'application/json',
          })
          await expect(toggle).toBeFocused()
          await toggle.press('Space')
          await expectTableTextControl(expect, page, 'a', false)
          await settleTableLayout(page)
          await expectTableTextTrack(expect, page, 'a', lines)
          await expect(toggle).toBeFocused()
        }
      })
    }
  }
})

test.describe('fixed rem text window stylesheet invalidation', () => {
  test.use({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })

  for (const content of ['text', 'parts'] as const) {
    test(`H02/H06 height ${content}: natural font/spacing changes remeasure without a DOM nudge`, async ({
      page,
    }, info) => {
      const policy = { mode: 'height', rem: 4.5 } as const
      await openTableFixture(
        expect,
        page,
        '/__table_geometry/index.html',
        'geometry-fixture',
      )
      await page.getByTestId('geometry-height-mode').selectOption('height')
      await page.getByTestId('geometry-rem').selectOption('4.5')
      await page.getByTestId('geometry-height-content').selectOption(content)
      await press(page, 'geometry-reading')
      await setTableEnvironment(page, { kind: 'font', size: 20 })
      await settleTableLayout(page)
      const region = tableTextRegion(page, 'b')
      const toggle = tableTextToggle(page, 'b')
      const cap = await watchHeight(region)
      const width = await region.evaluate((node) => node.clientWidth)
      await expect(toggle).toHaveCount(0)
      const evidence = async (
        phase: string,
        expanded = false,
        overflow = false,
      ) => {
        await info.attach(`${content}-${phase}`, {
          body: await expectTableTextGeometry(
            expect,
            page,
            'b',
            policy,
            expanded,
            overflow,
          ),
          contentType: 'application/json',
        })
      }
      await evidence('before')
      // Only a head stylesheet changes. No class/render/resize/requestMeasure
      // on the text window can supply the missing overflow invalidation.
      for (const size of [48, 96]) {
        await setTableEnvironment(page, {
          kind: 'text-font',
          size,
          lineHeight: 2,
        })
        await settleTableLayout(page)
        expect(await region.evaluate((node) => node.scrollHeight)).toBe(
          size * 2,
        )
        expect(await region.evaluate((node) => node.clientHeight)).toBe(90)
        expect(await region.evaluate((node) => node.clientWidth)).toBe(width)
        await cap.unchanged()
        await expectTableTextControl(expect, page, 'b', false)
      }
      await evidence('font96-glyph-outside90', false, true)
      await setTableEnvironment(page, {
        kind: 'text-font',
        size: 16,
        lineHeight: 1.5,
      })
      await settleTableLayout(page)
      await expect(toggle).toHaveCount(0)
      await cap.unchanged()
      await evidence('small-again')
      await setTableEnvironment(page, {
        kind: 'text-font',
        size: 96,
        lineHeight: 2,
      })
      await expectTableTextControl(expect, page, 'b', false)
      await toggle.focus()
      const button = await toggle.elementHandle()
      await toggle.press('Enter')
      await expectTableTextControl(expect, page, 'b', true)
      await settleTableLayout(page)
      await evidence('expanded', true)
      await setTableEnvironment(page, {
        kind: 'text-font',
        size: 16,
        lineHeight: 1.5,
      })
      await settleTableLayout(page)
      await expectTableTextControl(expect, page, 'b', true)
      await toggle.press('Enter')
      await expectTableTextControl(expect, page, 'b', false)
      await settleTableLayout(page)
      await expect(toggle).toBeFocused()
      expect(
        await toggle.evaluate((node, before) => node === before, button),
      ).toBe(true)
      await page.getByTestId('geometry-height-content').focus()
      await expect(toggle).toHaveCount(0)
      await evidence('collapsed-small')
      await setTableEnvironment(page, { kind: 'container', narrow: true })
      await setTableEnvironment(page, { kind: 'spacing', enabled: true })
      await info.attach(`${content}-late-font-condition`, {
        body: await loadLateTableFont(
          expect,
          page,
          'geometry-body',
          'geometry-name-b',
        ),
        contentType: 'application/json',
      })
      await settleTableLayout(page)
      await expectTableTextControl(expect, page, 'a', false)
      await tableTextToggle(page, 'a').click()
      await expectTableTextControl(expect, page, 'a', true)
      await settleTableLayout(page)
      await info.attach(`${content}-width-spacing-font`, {
        body: await expectTableTextGeometry(expect, page, 'a', policy, true),
        contentType: 'application/json',
      })
    })
  }
})
