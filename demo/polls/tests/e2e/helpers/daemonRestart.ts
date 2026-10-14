import { expect, type Page } from '@playwright/test'

import {
  readRestartWorker as readWorker,
  type RestartWorker,
} from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage, PAGE_READY } from './page.js'

export {
  RESTART_AGENT,
  withStoppedRestartWorker,
} from '../../../../../framework/frontend/e2e/index.js'

/** Read the real Workers window using this demo's navigation and Playwright. */
export async function readRestartWorker(page: Page): Promise<RestartWorker> {
  return readWorker(
    page,
    (page) => gotoPage(page, '/hilos/daemon/standalone/workers', PAGE_READY),
    async (predicate) => {
      await expect.poll(predicate).toBe(true)
    },
  )
}
