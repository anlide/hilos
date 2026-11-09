import type { Page } from '@playwright/test'
import { test, expect } from '../test.js'
import {
  expectPageReady,
  expectPageRefused,
  gotoPage,
  PAGE_REFUSED,
} from '../../../../../framework/frontend/e2e/index.js'

// An address no route claims shows Page Not Found locally and stays on that
// address; home, back and forward move through history without a reload.
// A public path nginx hands to the shell does the same, and reloading it
// returns that shell (HTTP 200) with the screen still in place.

test('unknown addresses stay refused through home, history and reload', async ({
  page,
}) => {
  await test.step('shows Page Not Found for an address no route claims', async () => {
    for (const path of ['/nope', '/hilos/xyz', '/hilos/app/moderator']) {
      await gotoPage(expect, page, path, PAGE_REFUSED)
      expect(new URL(page.url()).pathname).toBe(path)

      const error = page.getByTestId('page-error')
      await expect(error).toHaveCount(1)
      await expect(error).toHaveAttribute('data-error-code', '404')
      await expect(error).toContainText('404')
      await expect(error).toContainText('Page Not Found')
      await expect(error).toContainText(
        'The page you are looking for does not exist.',
      )
    }
  })

  await test.step('returns home from an unknown address and restores it from history', async () => {
    let fullLoads = 0
    page.on('load', () => {
      fullLoads += 1
    })

    // This address used to open the chat home with no error. Leaving it is the
    // home link; back and forward only traverse that entry.
    const missed = '/hilos/app/moderator'
    await gotoPage(expect, page, missed, PAGE_REFUSED)
    await expect(page.getByTestId('page-error')).toBeVisible()
    const loadsAfterColdLoad = fullLoads

    await page.getByTestId('page-error-home').click()
    await expectPageReady(expect, page)
    expect(new URL(page.url()).pathname).toBe('/')
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    expect(fullLoads).toBe(loadsAfterColdLoad)

    await page.goBack()
    await expectPageRefused(expect, page)
    expect(new URL(page.url()).pathname).toBe(missed)
    const error = page.getByTestId('page-error')
    await expect(error).toHaveCount(1)
    await expect(error).toHaveAttribute('data-error-code', '404')
    await expect(error).toContainText('Page Not Found')
    await expect(error).toContainText(
      'The page you are looking for does not exist.',
    )
    expect(fullLoads).toBe(loadsAfterColdLoad)

    await page.goForward()
    await expectPageReady(expect, page)
    expect(new URL(page.url()).pathname).toBe('/')
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    expect(fullLoads).toBe(loadsAfterColdLoad)
  })

  await test.step('reloads a cold unknown public path as Page Not Found and walks history in place', async () => {
    let fullLoads = 0
    page.on('load', () => {
      fullLoads += 1
    })

    const missed = '/nope'
    await gotoPage(expect, page, missed, PAGE_REFUSED)
    expect(new URL(page.url()).pathname).toBe(missed)
    await expectUnknownAddress(page)

    // The document is the SPA shell. HTTP 200 is that fallback, not proof
    // that this address is a page.
    const document = await page.reload()
    expect(document).not.toBeNull()
    expect(document?.status()).toBe(200)
    await expectPageRefused(expect, page)
    expect(new URL(page.url()).pathname).toBe(missed)
    await expectUnknownAddress(page)
    const loadsAfterReload = fullLoads

    await page.getByTestId('page-error-home').click()
    await expectPageReady(expect, page)
    expect(new URL(page.url()).pathname).toBe('/')
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    expect(fullLoads).toBe(loadsAfterReload)

    await page.goBack()
    await expectPageRefused(expect, page)
    expect(new URL(page.url()).pathname).toBe(missed)
    await expectUnknownAddress(page)
    expect(fullLoads).toBe(loadsAfterReload)

    await page.goForward()
    await expectPageReady(expect, page)
    expect(new URL(page.url()).pathname).toBe('/')
    await expect(page.getByTestId('page-error')).toHaveCount(0)
    expect(fullLoads).toBe(loadsAfterReload)
  })
})

// The local 404 for an address no route claims: the outlet marker, the
// screen, the exact copy, and the home link.
async function expectUnknownAddress(page: Page): Promise<void> {
  await expect(page.getByTestId('hilos-page-state')).toHaveAttribute(
    'data-state',
    'error',
  )
  const error = page.getByTestId('page-error')
  await expect(error).toHaveCount(1)
  await expect(error).toHaveAttribute('data-error-code', '404')
  await expect(error).toContainText('404')
  await expect(error).toContainText('Page Not Found')
  await expect(error).toContainText(
    'The page you are looking for does not exist.',
  )
  await expect(page.getByTestId('page-error-home')).toBeVisible()
}
