import { test, expect, type Page, type TestInfo } from '@playwright/test'

import {
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
  watchTableModalFocus,
  type TableEnvironment,
  type TableGeometryWatch,
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

async function press(page: Page, id: string): Promise<void> {
  const button = shownByTestId(page, id)
  await button.scrollIntoViewIfNeeded()
  await expect(button).toBeVisible()
  await expect(button).toBeEnabled()
  await button.focus()
  await button.click()
}

async function observeAllocation(
  page: Page,
  key: string,
  projection: 'wide' | 'card',
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
  await input.fill('')
  await input.pressSequentially(String(value), { delay: 10 })
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
        test(`G03/G04 detail=${open} work=${work} expanded-text=${text}`, async ({
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

  test('G09 all 18 freshness × pending × detail combinations preserve marks and content tracks', async ({
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
    const initial = await expectTableNativeLifecycle(expect, page, true)
    await press(page, 'geometry-own')
    await press(page, 'geometry-churn')
    await expect(page.getByTestId('geometry-state')).toContainText(
      'cycle-23:departed',
    )
    const churned = (await page.getByTestId('geometry-state').textContent())!
    expect(churned.split(',')).toHaveLength(31)
    expect(churned).toContain('cycle-0:departed')
    await expectTableNativeLifecycle(expect, page, true)
    await press(page, 'geometry-reconnect')
    await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
    await settleTableLayout(page)
    expect(await expectTableNativeLifecycle(expect, page, true)).toBe(initial)
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
    expect(await expectTableNativeLifecycle(expect, page, true)).toBe(initial)
    for (let cycle = 0; cycle < 3; cycle++) {
      await press(page, 'geometry-unmount')
      await settleTableLayout(page)
      await expectTableNativeLifecycle(expect, page, false)
      await press(page, 'geometry-mount')
      await settleTableLayout(page)
      expect(await expectTableNativeLifecycle(expect, page, true)).toBe(initial)
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

  test('B05 modal keeps focus across a replacement occurrence and returns to its own search', async ({
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
    await expect(modal).toBeFocused()
    await modal.press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await page.getByTestId('modal-close').press('Tab')
    await expect(page.getByTestId('modal-close')).toBeFocused()
    await modal.press('Escape')
    await expect(modal).toHaveCount(0)
    await expect(page.getByTestId('hilos-table-search')).toBeFocused()
    const returned = await focus.read()
    expect(returned.inDialog).toBe(false)
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
