import { test, expect } from '@playwright/test'
import { watchHeight, watchTop } from '../../../../../framework/frontend/e2e/index.js'
import { gotoPage } from '../helpers/page'

// The support block at the end of /about (HIL-840), as a guest: no session is
// needed because the block has no per-reader part at all.
//
// What only a browser can answer here is the honest refusal — that pressing
// Subscribe puts the sentence on screen instead of leaving a dead button or
// pretending something was charged. Nothing goes over the wire, so there is
// nothing else for this spec to wait on.

test('a tier can be chosen and Subscribe answers with the honest refusal', async ({
  page,
}) => {
  await gotoPage(page, '/about')
  await expect(page.getByTestId('hilos-about-support')).toBeVisible()

  await page.getByTestId('hilos-about-support-open').click()
  const coffee = page.getByTestId('hilos-about-tier-coffee')
  await expect(coffee).toBeVisible()
  await coffee.click()
  await expect(coffee).toHaveAttribute('aria-pressed', 'true')

  const slot = page.getByTestId('hilos-about-refusal-slot')
  await expect(slot).toBeVisible()
  const slotRoom = await watchHeight(slot)
  const coffeeTop = await watchTop(coffee)

  await page.getByTestId('hilos-about-subscribe').click()

  const refusal = page.getByTestId('hilos-about-refusal')
  await expect(refusal).toBeVisible()
  await expect(refusal).toContainText('The payment system is not built yet.')
  await expect(refusal).toContainText('no subscription was created')

  await slotRoom.unchanged()
  await coffeeTop.unchanged()

  // The plate shows and the region speaks: the sentence also reaches the live
  // region that stood in the dialog before there was anything to announce.
  await expect(page.getByTestId('hilos-about-live-assertive')).toContainText(
    'The payment system is not built yet.',
  )
})
