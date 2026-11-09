import type {
  Expect,
  PlaywrightTestArgs,
  PlaywrightTestOptions,
  PlaywrightWorkerArgs,
  PlaywrightWorkerOptions,
  TestType,
} from '@playwright/test'

import { pressDigits } from './dateTimePickerFieldScenarios.js'
import { openTableFixture } from './table.js'

type DateTimePickerCalendarCase = [
  title: string,
  body: (args: Pick<PlaywrightTestArgs, 'page'>) => Promise<void>,
]

interface DateTimePickerCalendarCases {
  pick: DateTimePickerCalendarCase
  keyboard: DateTimePickerCalendarCase
  period: DateTimePickerCalendarCase
  fold: DateTimePickerCalendarCase
  gap: DateTimePickerCalendarCase
  timeOnly: DateTimePickerCalendarCase
}

/** Register the calendar journeys with each SDK's local runner and fixtures. */
export function registerDateTimePickerCalendarScenarios<
  TestArgs extends PlaywrightTestArgs & PlaywrightTestOptions,
  WorkerArgs extends PlaywrightWorkerArgs & PlaywrightWorkerOptions,
>(
  test: TestType<TestArgs, WorkerArgs>,
  expect: Expect,
  register: (
    calendarTest: TestType<TestArgs, WorkerArgs>,
    cases: DateTimePickerCalendarCases,
  ) => void,
): void {
  const cases: DateTimePickerCalendarCases = {
    pick: [
      'a day picked in the calendar keeps the typed time and the focus comes back to its opener',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByTestId('fixture-min').selectOption('2026-10-13')
        await page.getByTestId('fixture-load').click()
        const opener = page.getByRole('button', {
          name: 'Open calendar',
          exact: true,
        })
        const panel = page.getByRole('dialog', { name: 'Choose date' })
        const result = page.getByTestId('fixture-result')

        await opener.click()
        const selected = panel.getByRole('gridcell', {
          name: 'Saturday, 17 October 2026',
        })
        await expect(selected).toBeFocused()
        await expect(selected).toHaveAttribute('aria-selected', 'true')
        await expect(
          panel.getByRole('gridcell', { name: 'Tuesday, 13 October 2026' }),
        ).toHaveAttribute('aria-current', 'date')
        await expect(
          panel.getByRole('gridcell', { name: 'Monday, 12 October 2026' }),
        ).toHaveAttribute('aria-disabled', 'true')

        await panel
          .getByRole('gridcell', { name: 'Tuesday, 20 October 2026' })
          .click()
        await expect(result).toHaveText(
          '{"status":"valid","value":1792468800000}',
        )
        await expect(panel).toBeVisible()
        await panel.getByRole('button', { name: 'Increase Minutes' }).click()
        await expect(result).toHaveText(
          '{"status":"valid","value":1792468860000}',
        )
        await expect(
          panel.getByRole('spinbutton', { name: 'Minutes', exact: true }),
        ).toBeFocused()
        await page.keyboard.press('Escape')
        await expect(panel).toHaveCount(0)
        await expect(opener).toBeFocused()

        await opener.click()
        await expect(panel).toBeVisible()
        await page.getByRole('heading', { level: 1 }).click()
        await expect(panel).toHaveCount(0)
        await expect(opener).not.toBeFocused()
      },
    ],

    keyboard: [
      'months and years walk by keys and a date closes on its choice',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByTestId('fixture-kind').selectOption('date')
        const panel = page.getByRole('dialog', { name: 'Choose date' })
        const opener = page.getByRole('button', { name: 'Open calendar' })

        await page.getByRole('spinbutton', { name: 'Day', exact: true }).focus()
        await page.keyboard.press('Alt+ArrowDown')
        await expect(
          panel.getByRole('gridcell', { name: 'Tuesday, 13 October 2026' }),
        ).toBeFocused()
        await page.keyboard.press('PageDown')
        await expect(
          panel.getByRole('gridcell', { name: 'Friday, 13 November 2026' }),
        ).toBeFocused()
        await page.keyboard.press('Shift+PageUp')
        await expect(
          panel.getByRole('gridcell', { name: 'Thursday, 13 November 2025' }),
        ).toBeFocused()
        await page.keyboard.press('ArrowRight')
        await expect(
          panel.getByRole('gridcell', { name: 'Friday, 14 November 2025' }),
        ).toBeFocused()
        await page.keyboard.press('End')
        await expect(
          panel.getByRole('gridcell', { name: 'Sunday, 16 November 2025' }),
        ).toBeFocused()
        await page.keyboard.press('Enter')
        await expect(page.getByTestId('fixture-result')).toHaveText(
          '{"status":"valid","value":"2025-11-16"}',
        )
        await expect(panel).toHaveCount(0)
        await expect(opener).toBeFocused()
      },
    ],

    period: [
      'a period takes two presses, and a press before its start begins again',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByTestId('fixture-kind').selectOption('date')
        await page.getByTestId('fixture-count').selectOption('range')
        const opener = page.getByRole('button', { name: 'Open calendar' })
        const panel = page.getByRole('dialog', { name: 'Choose date' })
        const result = page.getByTestId('fixture-result')

        await opener.click()
        await panel
          .getByRole('gridcell', { name: 'Saturday, 17 October 2026' })
          .click()
        await expect(result).toHaveText('{"status":"unresolved"}')
        await expect(panel).toBeVisible()
        await panel
          .getByRole('gridcell', { name: 'Wednesday, 21 October 2026' })
          .click()
        await expect(result).toHaveText(
          '{"status":"valid","value":["2026-10-17","2026-10-21"]}',
        )
        await expect(panel).toHaveCount(0)
        await expect(opener).toBeFocused()

        await opener.click()
        await panel
          .getByRole('gridcell', { name: 'Monday, 19 October 2026' })
          .click()
        await expect(result).toHaveText(
          '{"status":"valid","value":["2026-10-19","2026-10-21"]}',
        )
        await expect(panel).toBeVisible()
        await panel
          .getByRole('gridcell', { name: 'Thursday, 15 October 2026' })
          .click()
        await expect(result).toHaveText('{"status":"unresolved"}')
        await expect(panel).toBeVisible()
        await panel
          .getByRole('gridcell', { name: 'Friday, 16 October 2026' })
          .click()
        await expect(result).toHaveText(
          '{"status":"valid","value":["2026-10-15","2026-10-16"]}',
        )
        await expect(panel).toHaveCount(0)
        await expect(opener).toBeFocused()
      },
    ],

    fold: [
      'a repeated hour is answered inside the open panel',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByRole('spinbutton', { name: 'Day', exact: true }).focus()
        await pressDigits(page, '251020260230')
        await page.getByRole('button', { name: 'Open calendar' }).click()
        const panel = page.getByRole('dialog', { name: 'Choose date' })
        const offsets = panel.getByRole('radiogroup', {
          name: 'Choose UTC offset',
        })
        const result = page.getByTestId('fixture-result')

        await expect(
          panel.getByText(
            '02:30 happens twice on 25/10/2026 in Europe/Warsaw. Which one?',
            { exact: true },
          ),
        ).toBeVisible()
        // Bootstrap hides the native radios; check through their visible labels.
        await offsets.getByText('First · UTC+02:00', { exact: true }).check()
        await expect(
          offsets.getByRole('radio', { name: 'First · UTC+02:00' }),
        ).toBeChecked()
        await expect(result).toHaveText(
          '{"status":"valid","value":1792888200000}',
        )
        await offsets.getByText('Second · UTC+01:00', { exact: true }).check()
        await expect(
          offsets.getByRole('radio', { name: 'Second · UTC+01:00' }),
        ).toBeChecked()
        await expect(result).toHaveText(
          '{"status":"valid","value":1792891800000}',
        )
        await expect(panel).toBeVisible()
      },
    ],

    gap: [
      'a missing hour is named in the panel and its step jumps over it',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByRole('spinbutton', { name: 'Day', exact: true }).focus()
        await pressDigits(page, '290320260230')
        await page.getByRole('button', { name: 'Open calendar' }).click()
        const panel = page.getByRole('dialog', { name: 'Choose date' })

        await expect(
          panel.getByText(
            '02:30 does not exist on 29/03/2026 in Europe/Warsaw: at 02:00 the clocks jump to 03:00.',
            { exact: true },
          ),
        ).toBeVisible()
        await panel.getByRole('button', { name: 'Decrease Hours' }).click()
        await panel.getByRole('button', { name: 'Increase Hours' }).click()
        await expect(page.getByTestId('fixture-result')).toHaveText(
          '{"status":"valid","value":1774747800000}',
        )
      },
    ],

    timeOnly: [
      'a time field opens its clock without a month',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByTestId('fixture-kind').selectOption('time')
        await page
          .getByRole('spinbutton', { name: 'Hours', exact: true })
          .focus()
        await pressDigits(page, '0600')
        const opener = page.getByRole('button', { name: 'Choose time' })
        const panel = page.getByRole('dialog', { name: 'Choose time' })

        await opener.click()
        await expect(panel.getByRole('grid')).toHaveCount(0)
        await expect(
          panel.getByRole('spinbutton', { name: 'Hours', exact: true }),
        ).toBeFocused()
        await panel.getByRole('button', { name: 'Increase Hours' }).click()
        await expect(page.getByTestId('fixture-result')).toHaveText(
          '{"status":"valid","value":"07:00:00"}',
        )
        await page.keyboard.press('Escape')
        await expect(panel).toHaveCount(0)
        await expect(opener).toBeFocused()

        for (const lock of ['busy', 'disabled']) {
          await test.step(`Escape still closes the time panel after a parent ${lock} update`, async () => {
            await opener.click()
            const hours = panel.getByRole('spinbutton', {
              name: 'Hours',
              exact: true,
            })
            await expect(hours).toBeFocused()
            await page.evaluate(() => {
              document.body.dataset.pickerEscapes = '0'
              document.body.addEventListener(
                'keydown',
                (event) => {
                  if (event.key === 'Escape')
                    document.body.dataset.pickerEscapes = '1'
                },
                { once: true },
              )
            })
            const checkbox = page.getByTestId(`fixture-${lock}`)
            // A parent update does not click outside or move the reader's focus.
            await checkbox.evaluate((node: HTMLInputElement) => {
              node.checked = true
              node.dispatchEvent(new Event('change', { bubbles: true }))
            })
            await expect(hours).toBeDisabled()
            await expect(opener).toBeDisabled()
            await expect(panel).toBeVisible()
            const activeElement = await page.evaluate(() => ({
              tag: document.activeElement?.tagName,
              role: document.activeElement?.getAttribute('role'),
              name: document.activeElement?.getAttribute('aria-label'),
            }))
            const annotations = test.info().annotations
            annotations.push({
              type: `picker ${lock} focus`,
              description: JSON.stringify(activeElement),
            })
            await page.keyboard.press('Escape')
            await expect(panel).toHaveCount(0)
            await expect(page.locator('body')).toHaveAttribute(
              'data-picker-escapes',
              '0',
            )
            await expect(page.getByTestId('fixture-result')).toHaveText(
              '{"status":"valid","value":"07:00:00"}',
            )
            await checkbox.evaluate((node: HTMLInputElement) => {
              node.checked = false
              node.dispatchEvent(new Event('change', { bubbles: true }))
            })
            await expect(opener).toBeEnabled()
          })
        }

        for (const lock of ['busy', 'disabled']) {
          await test.step(`Escape stays inside an initially open ${lock} time panel`, async () => {
            const checkbox = page.getByTestId(`fixture-${lock}`)
            await checkbox.check()
            const changes = await page
              .getByTestId('fixture-changes')
              .textContent()
            await page.evaluate(() => {
              document.body.dataset.pickerEscapes = '0'
              document.body.addEventListener(
                'keydown',
                (event) => {
                  if (event.key === 'Escape')
                    document.body.dataset.pickerEscapes = '1'
                },
                { once: true },
              )
            })
            await page.getByTestId('fixture-mount-open-time').click()
            await expect(panel).toBeVisible()
            await expect(
              panel.getByRole('spinbutton', { name: 'Hours', exact: true }),
            ).toBeDisabled()
            await expect(opener).toBeDisabled()
            const before = await page.evaluate(() => ({
              tag: document.activeElement?.tagName,
              role: document.activeElement?.getAttribute('role'),
              name: document.activeElement?.getAttribute('aria-label'),
              source: document.activeElement?.getAttribute('data-id'),
              open: document
                .querySelector('[aria-haspopup="dialog"]')
                ?.getAttribute('aria-expanded'),
            }))
            await page.keyboard.press('Escape')
            const after = await page.evaluate(() => ({
              open: document
                .querySelector('[aria-haspopup="dialog"]')
                ?.getAttribute('aria-expanded'),
              propagated: document.body.dataset.pickerEscapes,
            }))
            await test.info().attach(`initial-open-${lock}`, {
              body: JSON.stringify({ before, after }),
              contentType: 'application/json',
            })
            expect.soft(before).toMatchObject({
              role: 'dialog',
              name: 'Choose time',
              open: 'true',
            })
            expect.soft(after).toEqual({ open: 'false', propagated: '0' })
            await expect.soft(panel).toHaveCount(0)
            await expect(page.getByTestId('fixture-result')).toHaveText(
              '{"status":"valid","value":"07:00:00"}',
            )
            await expect(page.getByTestId('fixture-changes')).toHaveText(
              changes!,
            )
            await checkbox.uncheck()
            await expect(opener).toBeEnabled()
          })
        }
      },
    ],
  }
  register(test, cases)
}
