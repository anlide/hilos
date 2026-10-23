import { test, expect, type Page, type TestInfo } from '@playwright/test'

import {
  expectDataFreeDeparture,
  expectInertTableHolders,
  expectTableNativeLifecycle,
  expectTableProgressCoverage,
  expectTableRowOrder,
  expectTableWrappingStress,
  openTableFixture,
  settleTableLayout,
  shownByTestId,
  watchHeight,
  watchTableAllocation,
  watchTableFirstLive,
  watchTableGeometry,
  watchTableHandoff,
  watchTableModalFocus,
  watchTableNativeLifecycle,
  type TableGeometryWatch,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — Angular AOT fixture consuming the installed SDK and Sass.
// Native renderer proof with synthetic ingress; server delivery is a separate spec.
const SURVIVORS = ['d', 'e', 'f']
const KEYS = ['a', 'b', 'c', ...SURVIVORS, 'g']
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

async function number(page: Page, value: number): Promise<void> {
  const input = page.getByTestId('geometry-position')
  await input.fill('')
  await input.pressSequentially(String(value), { delay: 10 })
}

async function retired(page: Page, key: string, reason = 'Removed') {
  await expectDataFreeDeparture(expect, page, key, reason, [
    `geometry-name-${key}`,
    `geometry-text-${key}`,
    `geometry-badge-${key}`,
    `geometry-action-${key}`,
    `geometry-details-${key}`,
    `geometry-restore-${key}`,
    `geometry-delete-${key}`,
    `geometry-detail-${key}`,
    `geometry-detail-action-${key}`,
    `geometry-work-${key}`,
  ])
  await expect(page.getByTestId('geometry-state')).toContainText(
    `${key}:departed`,
  )
  await expect(page.getByTestId(`hilos-table-card-${key}`)).toBeHidden()
}

async function watch(page: Page, rowKeys = KEYS) {
  return watchTableGeometry(expect, page, {
    bodyId: 'geometry-body',
    projection: 'wide',
    rowKeys,
    lastKey: 'g',
  })
}

// Every observer starts before ingress and is stopped/attached even on failure.
// Its private browser frames live only for this test, with no DB/RT readers.
async function observed(
  page: Page,
  info: TestInfo,
  name: string,
  observation: TableGeometryWatch,
  run: (observation: TableGeometryWatch) => Promise<void>,
): Promise<void> {
  try {
    await run(observation)
    await settleTableLayout(page)
    await observation.check()
  } finally {
    await observation.stop()
    await info.attach(`${name}-frames`, {
      body: await observation.evidence(),
      contentType: 'application/json',
    })
  }
}

async function expandedWork(page: Page, key: string): Promise<void> {
  await page.getByTestId('geometry-target').selectOption(key)
  await page.getByTestId('geometry-detail').selectOption('expanded')
  await page.getByTestId('geometry-text').check()
  await press(page, 'geometry-configure')
  await press(page, 'geometry-work')
  await expect(shownByTestId(page, `geometry-detail-${key}`)).toBeVisible()
  await expect(shownByTestId(page, `geometry-work-${key}`)).toBeVisible()
  await expectTableProgressCoverage(expect, page, key, `geometry-text-${key}`)
  await settleTableLayout(page)
}

test.beforeEach(async ({ page }) => {
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
  if (info.status !== info.expectedStatus) {
    await info.attach('native-layout', {
      body: await page.screenshot({ fullPage: true }),
      contentType: 'image/png',
    })
  }
})

for (const mode of ['sequential', 'batch'] as const) {
  test(`G01/G04 ${mode}: widest, tallest and last preserve every boundary`, async ({
    page,
  }, info) => {
    await expandedWork(page, 'c')
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
    await observed(page, info, mode, await watch(page), async (geometry) => {
      if (mode === 'batch') await press(page, 'geometry-batch')
      else {
        for (const key of ['a', 'b', 'c', 'g']) {
          await page.getByTestId('geometry-target').selectOption(key)
          await press(page, 'geometry-remove')
          await expect(page.getByTestId('geometry-state')).toContainText(
            `${key}:remove`,
          )
          if (key === 'c') {
            await expect(page.getByTestId('geometry-work-c')).toHaveCount(0)
            await expect(shownByTestId(page, 'geometry-detail-c')).toBeVisible()
            await geometry.check()
            await press(page, 'geometry-complete')
            await geometry.check()
          }
          await press(page, 'geometry-apply')
          await retired(page, key)
          await geometry.check()
        }
      }
      for (const key of ['a', 'b', 'c', 'g']) await retired(page, key)
      await page.getByTestId('geometry-target').selectOption('c')
      await press(page, 'geometry-complete')
      await expectTableRowOrder(expect, page, KEYS)
    })
  })
}

for (const [reason, sentence] of REASONS) {
  test(`G02 ${reason}: both mounted branches retain only the reason`, async ({
    page,
  }, info) => {
    await page.getByTestId('geometry-reason').selectOption(reason)
    await shownByTestId(page, 'hilos-table-select-a').check()
    await observed(page, info, reason, await watch(page), async (geometry) => {
      await press(page, 'geometry-remove')
      await expect(page.getByTestId('geometry-state')).toContainText('a:remove')
      await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
        'Record a',
      )
      await geometry.check()
      await press(page, 'geometry-apply')
      await retired(page, 'a', sentence)
      await expect(page.getByTestId('geometry-state')).toHaveAttribute(
        'data-selected',
        'null',
      )
      await expectInertTableHolders(expect, page)
    })
  })
}

for (const ingress of ['own', 'normalize'] as const) {
  test(`G03/G05 ${ingress}: capture precedes destructive normalization`, async ({
    page,
  }, info) => {
    await expandedWork(page, 'a')
    await observed(page, info, ingress, await watch(page), async () => {
      await press(page, `geometry-${ingress}`)
      await retired(page, 'a')
      if (ingress === 'normalize')
        await expect(page.getByTestId('geometry-state')).toHaveAttribute(
          'data-sync',
          'true',
        )
      await press(page, 'geometry-complete')
    })
  })
}

test('G04 completed work never returns in a departed footprint', async ({
  page,
}, info) => {
  await press(page, 'geometry-work')
  await expect(shownByTestId(page, 'geometry-work-a')).toBeVisible()
  await press(page, 'geometry-complete')
  await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
  await settleTableLayout(page)
  await observed(page, info, 'completed-work', await watch(page), async () => {
    await press(page, 'geometry-own')
    await retired(page, 'a')
  })
})

for (const detail of ['absent', 'collapsed', 'expanded'] as const) {
  test(`G09 ${detail}: state/header marks keep content tracks and the page badge`, async ({
    page,
  }, info) => {
    await page.getByTestId('geometry-detail').selectOption(detail)
    await press(page, 'geometry-configure')
    await settleTableLayout(page)
    await observed(
      page,
      info,
      `marks-${detail}`,
      await watch(page),
      async (geometry) => {
        await page.getByTestId('geometry-stale').check()
        await press(page, 'geometry-configure')
        await expect(
          shownByTestId(page, 'hilos-table-stale-row-a'),
        ).toBeVisible()
        await expect(
          page.getByTestId('hilos-table-stale-column-name'),
        ).toBeVisible()
        await press(page, 'geometry-badge')
        await expect(shownByTestId(page, 'geometry-badge-a')).toHaveText(
          'Paused',
        )
        await shownByTestId(page, 'hilos-table-select-a').check()
        for (const pending of ['move', 'remove']) {
          await page.getByTestId('geometry-pending').selectOption(pending)
          await press(page, 'geometry-configure')
          await expect(
            shownByTestId(page, `hilos-table-pending-${pending}-a`),
          ).toBeVisible()
          await geometry.check()
        }
        if (detail === 'absent')
          await expect(page.getByTestId('hilos-table-expand-a')).toHaveCount(0)
        else
          await expect(
            shownByTestId(page, 'hilos-table-expand-a'),
          ).toHaveAttribute('aria-expanded', String(detail === 'expanded'))
        await expectInertTableHolders(expect, page)
        await press(page, 'geometry-apply')
        await retired(page, 'a')
      },
    )
  })
}

for (const timing of [
  'before-remove',
  'before-apply',
  'after-apply',
  'positive',
] as const) {
  test(`G06/G07 ${timing}: all-placeholder window retains tracks through append and own create`, async ({
    page,
  }, info) => {
    const state = page.getByTestId('geometry-state')
    await shownByTestId(page, 'hilos-table-select-a').check()
    await press(page, 'geometry-set-work')
    const requests = (await state.getAttribute('data-requests'))!
    if (timing === 'before-remove') await press(page, 'geometry-count-zero')
    if (timing === 'positive') await press(page, 'geometry-count-positive')
    await observed(
      page,
      info,
      `all-${timing}`,
      await watch(page),
      async (geometry) => {
        for (const key of KEYS) {
          await page.getByTestId('geometry-target').selectOption(key)
          await press(page, 'geometry-remove')
        }
        if (timing === 'before-apply') await press(page, 'geometry-count-zero')
        await expect(state).toContainText('g:remove')
        await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
          'Record a',
        )
        await geometry.check()
        await press(page, 'geometry-apply')
        if (timing === 'after-apply') await press(page, 'geometry-count-zero')
        for (const key of KEYS) await retired(page, key)
        await expect(state).toHaveAttribute(
          'data-count',
          timing === 'positive' ? '30' : '0',
        )
        await expect(state).toHaveAttribute('data-range', '0:0')
        await expect(state).toHaveAttribute('data-body', 'rows')
        await expect(state).toHaveAttribute('data-selected', 'null')
        await expect(state).toHaveAttribute('data-requests', requests)
        await expect(state).toHaveAttribute(
          'data-set-work',
          'set-table:set-bulk:finished',
        )
        await expect(page.getByTestId('hilos-table-count')).toHaveText(
          '0 – 0 of ' + (timing === 'positive' ? '30' : '0'),
        )
        await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
        await expect(page.getByTestId('hilos-table-empty')).toHaveCount(0)
      },
    )
    await observed(
      page,
      info,
      `append-${timing}`,
      await watch(page),
      async () => {
        await page.getByTestId('geometry-target').selectOption('h')
        await press(page, 'geometry-append')
        await expect(shownByTestId(page, 'geometry-name-h')).toBeVisible()
        await expectTableRowOrder(expect, page, [...KEYS, 'h'])
        await expect(state).toHaveAttribute('data-range', '1:1')
        await expect(state).toHaveAttribute(
          'data-count',
          timing === 'positive' ? '31' : '1',
        )
      },
    )
    await page.getByTestId('geometry-target').selectOption('i')
    await number(page, 0)
    await press(page, 'geometry-create')
    await expect(shownByTestId(page, 'geometry-name-i')).toBeVisible()
    await expectTableRowOrder(expect, page, [...KEYS, 'i', 'h'])
    for (const key of KEYS) await retired(page, key)
    await expect(state).toHaveAttribute('data-range', '1:2')
  })
}

test('G07 announcement and Apply do not fetch arrivals; Show replaces the window', async ({
  page,
}, info) => {
  await press(page, 'geometry-own')
  await retired(page, 'a')
  const state = page.getByTestId('geometry-state')
  const requests = (await state.getAttribute('data-requests'))!
  const room = await watchHeight(page.getByTestId('hilos-table-live-slot'))
  await observed(page, info, 'announcement', await watch(page), async () => {
    await press(page, 'geometry-announce')
    await expect(page.getByTestId('hilos-table-announce')).toBeVisible()
    await expect(page.getByTestId('geometry-name-j')).toHaveCount(0)
    await page.getByTestId('geometry-target').selectOption('b')
    await press(page, 'geometry-remove')
    await press(page, 'geometry-apply')
    await retired(page, 'b')
    await expect(state).toHaveAttribute('data-requests', requests)
    await room.unchanged()
  })
  await press(page, 'hilos-table-announce-show')
  await expect(state).toHaveAttribute(
    'data-requests',
    String(Number(requests) + 1),
  )
  await press(page, 'geometry-deliver')
  await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
  await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Fresh a')
})

for (const direction of ['after', 'before'] as const) {
  test(`G07/G10 own placement evicts the ${direction} edge and reuses a fresh key`, async ({
    page,
  }) => {
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
    const evicted = direction === 'after' ? 'd' : 'b'
    await page.getByTestId('geometry-target').selectOption(evicted)
    await shownByTestId(page, `hilos-table-select-${evicted}`).check()
    await expandedWork(page, evicted)
    await page.getByTestId('geometry-target').selectOption('h')
    await press(page, 'geometry-create')
    await expect(shownByTestId(page, 'geometry-name-h')).toBeVisible()
    await expectTableRowOrder(
      expect,
      page,
      direction === 'after' ? ['a', 'h', 'b', 'c'] : ['a', 'h', 'c', 'd'],
    )
    for (const prefix of ['name', 'work', 'detail'])
      await expect(
        page.getByTestId(`geometry-${prefix}-${evicted}`),
      ).toHaveCount(0)
    await expect(page.getByTestId('geometry-state')).toHaveAttribute(
      'data-selected',
      'null',
    )
    await retired(page, 'a')
    const identities = (await page
      .getByTestId('geometry-state')
      .getAttribute('data-identities'))!
    await page.getByTestId('geometry-target').selectOption('a')
    await press(page, 'geometry-create')
    await expect(shownByTestId(page, 'geometry-name-a')).toBeVisible()
    await expect(page.getByTestId('geometry-state')).not.toHaveAttribute(
      'data-identities',
      identities,
    )
    await expect(page.getByTestId('geometry-detail-a')).toHaveCount(0)
    await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
  })
}

test('G05 reused occurrence ignores late work from the departed row', async ({
  page,
}, info) => {
  await expandedWork(page, 'a')
  const identities = (await page
    .getByTestId('geometry-state')
    .getAttribute('data-identities'))!
  await press(page, 'geometry-reuse')
  await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
    'Replacement a',
  )
  await expect(page.getByTestId('geometry-state')).not.toHaveAttribute(
    'data-identities',
    identities,
  )
  await expect(page.getByTestId('geometry-detail-a')).toHaveCount(0)
  await expect(page.getByTestId('geometry-work-a')).toHaveCount(0)
  await settleTableLayout(page)
  await observed(page, info, 'reuse', await watch(page), async () => {
    await press(page, 'geometry-complete')
    await press(page, 'geometry-own')
    await retired(page, 'a')
  })
})

test('G10 allowed moves and live updates preserve an unrelated departed allocation', async ({
  page,
}, info) => {
  await press(page, 'geometry-own')
  await retired(page, 'a')
  await observed(
    page,
    info,
    'live-survivor',
    await watchTableAllocation(expect, page, 'a', 'wide'),
    async () => {
      await page.getByTestId('geometry-target').selectOption('d')
      await number(page, 1)
      await press(page, 'geometry-move')
      await expect(page.getByTestId('geometry-state')).toContainText('d:move')
      await expectTableRowOrder(expect, page, KEYS)
      await press(page, 'geometry-apply')
      await expect(page.getByTestId('geometry-state')).toHaveText(
        'a:departed,b:live,d:live,c:live,e:live,f:live,g:live',
      )
      await expectTableRowOrder(expect, page, [
        'a',
        'b',
        'd',
        'c',
        'e',
        'f',
        'g',
      ])
      await number(page, 4)
      await press(page, 'geometry-own-move')
      await expect(page.getByTestId('geometry-state')).toHaveText(
        'a:departed,b:live,c:live,e:live,f:live,d:live,g:live',
      )
      await expectTableRowOrder(expect, page, [
        'a',
        'b',
        'c',
        'e',
        'f',
        'd',
        'g',
      ])
      await expect(page.getByTestId('hilos-table-apply')).toHaveCount(0)
      await page.getByTestId('geometry-detail').selectOption('expanded')
      await press(page, 'geometry-configure')
      await expect(shownByTestId(page, 'geometry-detail-d')).toBeVisible()
      await settleTableLayout(page)
      const live = await watchTableAllocation(
        expect,
        page,
        'd',
        'wide',
        false,
        'd',
      )
      try {
        await press(page, 'geometry-live')
        await expect(shownByTestId(page, 'geometry-name-d')).toHaveText(
          'Updated d',
        )
        await expect(shownByTestId(page, 'geometry-detail-d')).toContainText(
          'Updated detail.',
        )
        await settleTableLayout(page)
        await live.changed('liveHeight', 'grew')
        await live.check()
      } finally {
        await live.stop()
        await info.attach('live-growth', {
          body: await live.evidence(),
          contentType: 'application/json',
        })
      }
      await press(page, 'geometry-work')
      await expect(shownByTestId(page, 'geometry-work-d')).toBeVisible()
      await press(page, 'geometry-complete')
      await expect(page.getByTestId('geometry-work-d')).toHaveCount(0)
      await retired(page, 'a')
    },
  )
})

for (const navigation of [
  'search',
  'filter',
  'order',
  'page',
  'show',
  'refresh',
  'reset',
] as const) {
  test(`G08 ${navigation}: delayed replacement releases the old window identity`, async ({
    page,
  }, info) => {
    await press(page, 'geometry-own')
    await retired(page, 'a')
    const state = page.getByTestId('geometry-state')
    const identities = (await state.getAttribute('data-identities'))!
    await press(page, 'geometry-count-positive')
    await page.getByTestId('geometry-navigation').selectOption(navigation)
    const old = await watchTableAllocation(expect, page, 'a', 'wide')
    try {
      await press(page, 'geometry-window')
      await expect(page.getByTestId('geometry-name-a')).toHaveCount(0)
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
      await press(page, 'geometry-deliver')
      await expect(state).not.toHaveAttribute('data-identities', identities)
      await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
      await expect(shownByTestId(page, 'geometry-name-a')).toHaveText('Fresh a')
      await settleTableLayout(page)
      await old.changed('extent', 'shrank')
    } finally {
      await old.stop()
      await info.attach(`window-${navigation}`, {
        body: await old.evidence(),
        contentType: 'application/json',
      })
    }
    await observed(
      page,
      info,
      `new-${navigation}`,
      await watch(page),
      async () => {
        await press(page, 'geometry-own')
        await retired(page, 'a')
      },
    )
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

test('G08/B04 reconnect, refusal, replacement and remount clean up native observers', async ({
  page,
}, info) => {
  await watchTableNativeLifecycle(page)
  await openTableFixture(
    expect,
    page,
    '/__table_geometry/index.html',
    'geometry-fixture',
  )
  await settleTableLayout(page)
  const initial = await expectTableNativeLifecycle(expect, page, true)
  try {
    await press(page, 'geometry-own')
    await retired(page, 'a')
    await page.getByTestId('geometry-snapshot').selectOption('short')
    const identities = (await page
      .getByTestId('geometry-state')
      .getAttribute('data-identities'))!
    await press(page, 'geometry-reconnect')
    await expect(page.getByTestId('hilos-table-placeholder')).toHaveCount(0)
    await expect(page.getByTestId('geometry-state')).not.toHaveAttribute(
      'data-identities',
      identities,
    )
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
      await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
      await settleTableLayout(page)
      await expectTableNativeLifecycle(expect, page, false)
      await press(page, 'geometry-mount')
      await expect(page.getByTestId('hilos-table-row-a')).toBeVisible()
      await settleTableLayout(page)
      expect(await expectTableNativeLifecycle(expect, page, true)).toBe(initial)
    }
  } finally {
    await info.attach('native-lifecycle', {
      body: await page.evaluate(() =>
        JSON.stringify(
          (
            window as unknown as { __tableLifecycle(): unknown }
          ).__tableLifecycle(),
        ),
      ),
      contentType: 'application/json',
    })
  }
})

test('G09 auto to fixed handoff preserves native connected boundaries', async ({
  page,
}, info) => {
  await press(page, 'geometry-unmount')
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  await observed(
    page,
    info,
    'handoff',
    await watchTableHandoff(expect, page, 'geometry-body'),
    async () => {
      await press(page, 'geometry-mount')
      await expect(page.getByTestId('hilos-table-row-a')).toBeVisible()
    },
  )
})

test('B01/B03 first live archive actions fit their tracks and take native clicks and keyboard', async ({
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
  await settleTableLayout(page)
  const first = await watchTableFirstLive(
    expect,
    page,
    'geometry-body',
    ['geometry-details-a', 'geometry-restore-a', 'geometry-delete-a'],
    'data-controller',
  )
  try {
    await press(page, 'geometry-append')
    await expect(page.getByTestId('hilos-table-row-a')).toBeVisible()
    await settleTableLayout(page)
    await info.attach('first-live-bounds', {
      body: await first.evidence(),
      contentType: 'application/json',
    })
    await first.check()
    for (const action of ['details', 'restore', 'delete']) {
      const button = shownByTestId(page, `geometry-${action}-a`)
      await expect(button).toHaveAccessibleName(
        action === 'details'
          ? 'Details'
          : action === 'restore'
            ? 'Restore'
            : 'Delete',
      )
      await press(page, `geometry-${action}-a`)
      await expect(page.getByTestId('geometry-actions')).toContainText(
        `a:${action}`,
      )
    }
    await shownByTestId(page, 'geometry-details-a').focus()
    for (const action of ['details', 'restore', 'delete']) {
      await expect(shownByTestId(page, `geometry-${action}-a`)).toBeFocused()
      await page.keyboard.press('Enter')
      if (action !== 'delete') await page.keyboard.press('Tab')
    }
    await expect(page.getByTestId('geometry-actions')).toHaveText(
      'a:details,a:restore,a:delete,a:details,a:restore,a:delete',
    )
  } finally {
    await info.attach('first-live-history', {
      body: await first.history(),
      contentType: 'application/json',
    })
    await first.stop()
  }
  await observed(
    page,
    info,
    'first-live-retained',
    await watchTableAllocation(expect, page, 'a', 'wide'),
    async () => {
      await press(page, 'geometry-own')
      await retired(page, 'a')
      await page.getByTestId('geometry-target').selectOption('b')
      await press(page, 'geometry-append')
      await expect(page.getByTestId('hilos-table-row-b')).toBeVisible()
    },
  )
})

for (const focus of [
  'action',
  'disclosure',
  'detail',
  'other',
  'root',
] as const) {
  test(`B05 ${focus}: F8 removal restores only the departing active control`, async ({
    page,
  }) => {
    if (focus === 'root') {
      await press(page, 'geometry-no-search')
      await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
      await settleTableLayout(page)
    }
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
    await control.focus()
    await expect(control).toBeFocused()
    await expect(control).toHaveAccessibleName(
      focus === 'disclosure'
        ? 'Show details'
        : focus === 'detail'
          ? 'Detail action'
          : 'Inspect',
    )
    if (focus === 'disclosure') {
      await control.press('Enter')
      await expect(control).toHaveAttribute('aria-expanded', 'true')
    }
    if (focus === 'detail' || focus === 'disclosure') {
      const ids = await page
        .getByTestId('hilos-table-expand-a')
        .evaluateAll((nodes) =>
          nodes.map((node) => node.getAttribute('aria-controls')),
        )
      expect(ids).toHaveLength(2)
      expect(ids[0]).not.toBe(ids[1])
    }
    await page.keyboard.press('F8')
    await retired(page, 'a')
    await settleTableLayout(page)
    await expect(
      focus === 'other'
        ? control
        : page.getByTestId(
            focus === 'root' ? 'hilos-viewport-table' : 'hilos-table-search',
          ),
    ).toBeFocused()
    const root = page.getByTestId('hilos-viewport-table')
    await expect(
      root.getByRole('table', { name: 'Geometry records', exact: true }),
    ).toHaveCount(1)
    await expect(root.getByRole('list')).toHaveCount(0)
    await expectInertTableHolders(expect, page)
  })
}

for (const outcome of [
  'connected',
  'removed',
  'snapshot',
  'root',
  'external',
] as const) {
  test(`B05 modal ${outcome}: trap and return follow the live focus-return policy`, async ({
    page,
  }, info) => {
    if (outcome === 'root') {
      await press(page, 'geometry-no-search')
      await expect(page.getByTestId('hilos-table-search')).toHaveCount(0)
      await settleTableLayout(page)
    }
    const trigger = shownByTestId(page, 'geometry-action-a')
    const focus = await watchTableModalFocus(trigger)
    const modal = page.getByTestId('modal')
    try {
      await trigger.focus()
      await trigger.press('Enter')
      await expect(modal).toBeFocused()
      await expect(modal).toHaveAttribute('role', 'dialog')
      await expect(modal).toHaveAttribute('aria-modal', 'true')
      await expect(modal).toHaveAccessibleName('Inspect geometry record')
      expect((await focus.read()).openerConnected).toBe(true)
      if (outcome === 'snapshot') {
        await page
          .getByTestId('geometry-modal-snapshot')
          .evaluate((button: HTMLButtonElement) => button.click())
        await expect(shownByTestId(page, 'geometry-name-a')).toHaveText(
          'Fresh a',
        )
        await expect(modal).toBeFocused()
        const replaced = await focus.read()
        expect(replaced.openerConnected).toBe(false)
        expect(replaced.sameTrigger).toBe(false)
        expect(replaced.visibleTriggers).toBe(1)
        expect(replaced.rootConnected).toBe(true)
        expect(replaced.inDialog).toBe(true)
        await expect(shownByTestId(page, 'geometry-action-a')).toHaveAttribute(
          'data-hilos-focus-return',
          'inspect-geometry',
        )
      } else if (outcome !== 'connected') {
        await page
          .getByTestId('geometry-modal-remove')
          .evaluate((button: HTMLButtonElement) => button.click())
        await retired(page, 'a')
        expect((await focus.read()).openerConnected).toBe(false)
        await expect(modal).toBeFocused()
      }
      // The two fixture controls and SDK close control cycle at both ends.
      await page.getByTestId('geometry-modal-snapshot').focus()
      await page.keyboard.press('Tab')
      await expect(page.getByTestId('modal-close')).toBeFocused()
      await page.keyboard.press('Shift+Tab')
      await expect(page.getByTestId('geometry-modal-snapshot')).toBeFocused()
      if (outcome === 'external') {
        await page.getByTestId('geometry-position').focus()
        // Synthetic close ingress leaves the user's external focus untouched.
        await page
          .getByTestId('modal-close')
          .evaluate((button: HTMLButtonElement) => button.click())
      } else await page.keyboard.press('Escape')
      await expect(modal).toHaveCount(0)
      await expect(
        outcome === 'connected'
          ? trigger
          : outcome === 'snapshot'
            ? shownByTestId(page, 'geometry-action-a')
            : page.getByTestId(
                outcome === 'external'
                  ? 'geometry-position'
                  : outcome === 'root'
                    ? 'hilos-viewport-table'
                    : 'hilos-table-search',
              ),
      ).toBeFocused()
      expect((await focus.read()).inDialog).toBe(false)
    } finally {
      await info.attach(`modal-${outcome}`, {
        body: JSON.stringify(await focus.history(), null, 2),
        contentType: 'application/json',
      })
      await focus.dispose()
    }
  })
}
