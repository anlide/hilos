import { readFile } from 'node:fs/promises'
import type { Locator, Page } from '@playwright/test'

/**
 * Download through the browser and read the bytes it actually saved.
 * @param page The browser page receiving the download.
 * @param trigger The stable-id download link.
 */
export async function downloadBytes(
  page: Page,
  trigger: Locator,
): Promise<{ filename: string; bytes: Buffer }> {
  await trigger.scrollIntoViewIfNeeded()
  await trigger.waitFor({ state: 'visible' })
  if (!(await trigger.isEnabled())) throw new Error('The download is disabled')
  await trigger.focus()
  const downloading = page.waitForEvent('download')
  await trigger.click()
  const download = await downloading
  const failure = await download.failure()
  if (failure !== null) throw new Error(`Download failed: ${failure}`)
  const path = await download.path()
  if (path === null) throw new Error('The browser did not save the download')
  return { filename: download.suggestedFilename(), bytes: await readFile(path) }
}
