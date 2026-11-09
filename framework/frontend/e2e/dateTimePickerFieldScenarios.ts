import type {
  Expect,
  Page,
  PlaywrightTestArgs,
  PlaywrightTestOptions,
  PlaywrightWorkerArgs,
  PlaywrightWorkerOptions,
  TestType,
} from '@playwright/test'

import { openTableFixture } from './table.js'

type DateTimePickerFieldCase = [
  title: string,
  body: (args: Pick<PlaywrightTestArgs, 'page'>) => Promise<void>,
]

interface DateTimePickerFieldCases {
  moment: DateTimePickerFieldCase
  gap: DateTimePickerFieldCase
  fold: DateTimePickerFieldCase
  period: DateTimePickerFieldCase
  factors: DateTimePickerFieldCase
}

/** Register the field journeys with each SDK's local runner and fixtures. */
export function registerDateTimePickerFieldScenarios<
  TestArgs extends PlaywrightTestArgs & PlaywrightTestOptions,
  WorkerArgs extends PlaywrightWorkerArgs & PlaywrightWorkerOptions,
>(
  test: TestType<TestArgs, WorkerArgs>,
  expect: Expect,
  register: (
    fieldTest: TestType<TestArgs, WorkerArgs>,
    cases: DateTimePickerFieldCases,
  ) => void,
): void {
  const cases: DateTimePickerFieldCases = {
    moment: [
      'a moment typed by parts walks the focus and publishes its exact moment',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        const field = page.getByRole('group', {
          name: 'Starts, Europe/Warsaw',
          exact: true,
        })
        const day = field.getByRole('spinbutton', { name: 'Day', exact: true })
        const month = field.getByRole('spinbutton', {
          name: 'Month',
          exact: true,
        })
        const year = field.getByRole('spinbutton', {
          name: 'Year',
          exact: true,
        })
        const hours = field.getByRole('spinbutton', {
          name: 'Hours',
          exact: true,
        })
        const minutes = field.getByRole('spinbutton', {
          name: 'Minutes',
          exact: true,
        })
        const result = page.getByTestId('fixture-result')

        await day.focus()
        await pressDigits(page, '17')
        await expect(month).toBeFocused()
        await pressDigits(page, '10')
        await expect(year).toBeFocused()
        await pressDigits(page, '2026')
        await expect(hours).toBeFocused()
        await pressDigits(page, '06')
        await expect(minutes).toBeFocused()
        await expect(result).toHaveText('{"status":"unresolved"}')
        await pressDigits(page, '00')
        await expect(result).toHaveText(
          '{"status":"valid","value":1792209600000}',
        )

        await page.keyboard.press('ArrowLeft')
        await expect(hours).toBeFocused()
        await page.keyboard.press('ArrowRight')
        await expect(minutes).toBeFocused()
        await page.keyboard.press('Backspace')
        await expect(minutes).toBeFocused()
        await expect(minutes).not.toHaveAttribute('aria-valuenow')
        await expect(result).toHaveText('{"status":"unresolved"}')
        await pressDigits(page, '00')
        await expect(result).toHaveText(
          '{"status":"valid","value":1792209600000}',
        )
      },
    ],

    gap: [
      'a missing hour refuses with its reason and the hour step jumps over it',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        const field = page.getByRole('group', {
          name: 'Starts, Europe/Warsaw',
          exact: true,
        })
        const hours = field.getByRole('spinbutton', {
          name: 'Hours',
          exact: true,
        })
        const minutes = field.getByRole('spinbutton', {
          name: 'Minutes',
          exact: true,
        })
        const result = page.getByTestId('fixture-result')
        const reason =
          '02:30 does not exist on 29/03/2026 in Europe/Warsaw: at 02:00 the clocks jump to 03:00.'
        const hint = page
          .getByText(reason, { exact: true })
          .and(page.locator('[aria-hidden="true"]'))

        await field
          .getByRole('spinbutton', { name: 'Day', exact: true })
          .focus()
        await pressDigits(page, '290320260230')
        await expect(minutes).toBeFocused()
        await expect(result).toHaveText('{"status":"unresolved"}')
        await expect(minutes).toHaveAttribute('aria-invalid', 'true')
        await expect(minutes).toHaveAccessibleDescription(reason)
        await expect(hint).toBeVisible()
        await page.getByTestId('fixture-kind').focus()
        await expect(hint).toBeHidden()
        await minutes.focus()
        await expect(hint).toBeVisible()

        await page.keyboard.press('ArrowLeft')
        await expect(hours).toBeFocused()
        await page.keyboard.press('ArrowDown')
        await expect(hours).toHaveValue('01')
        await expect(minutes).toHaveValue('30')
        await page.keyboard.press('ArrowUp')
        await expect(hours).toHaveValue('03')
        await expect(minutes).toHaveValue('30')
        await expect(result).toHaveText(
          '{"status":"valid","value":1774747800000}',
        )
        await expect(hint).toBeHidden()
      },
    ],

    fold: [
      'a repeated hour waits for a chosen offset',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        const field = page.getByRole('group', {
          name: 'Starts, Europe/Warsaw',
          exact: true,
        })
        const offset = field.getByRole('spinbutton', {
          name: 'UTC offset',
          exact: true,
        })
        const result = page.getByTestId('fixture-result')

        await field
          .getByRole('spinbutton', { name: 'Day', exact: true })
          .focus()
        await pressDigits(page, '251020260230')
        await expect(offset).toBeFocused()
        await expect(offset).not.toHaveAttribute('aria-valuenow')
        await expect(offset).not.toHaveAttribute('aria-invalid')
        await expect(offset).toHaveAccessibleDescription(
          '02:30 happens twice on 25/10/2026 in Europe/Warsaw. Which one?',
        )
        await expect(result).toHaveText('{"status":"unresolved"}')
        await page.keyboard.press('ArrowUp')
        await expect(offset).toHaveValue('UTC+02:00')
        await expect(result).toHaveText(
          '{"status":"valid","value":1792888200000}',
        )
        await page.keyboard.press('ArrowUp')
        await expect(offset).toHaveValue('UTC+01:00')
        await expect(result).toHaveText(
          '{"status":"valid","value":1792891800000}',
        )
      },
    ],

    period: [
      'a period in one field publishes a pair only when both ends are whole and does not sort them',
      async ({ page }) => {
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        await page.getByTestId('fixture-count').selectOption('range')
        const field = page.getByRole('group', {
          name: 'Starts, Europe/Warsaw',
          exact: true,
        })
        const fromMinutes = field.getByRole('spinbutton', {
          name: 'From Minutes',
          exact: true,
        })
        const toDay = field.getByRole('spinbutton', {
          name: 'To Day',
          exact: true,
        })
        const toMinutes = field.getByRole('spinbutton', {
          name: 'To Minutes',
          exact: true,
        })
        const result = page.getByTestId('fixture-result')

        await field
          .getByRole('spinbutton', { name: 'From Day', exact: true })
          .focus()
        await pressDigits(page, '171020260600')
        await expect(toDay).toBeFocused()
        await expect(result).toHaveText('{"status":"unresolved"}')
        await page.keyboard.press('Shift+Tab')
        await expect(fromMinutes).toBeFocused()
        await page.keyboard.press('Tab')
        await expect(toDay).toBeFocused()
        await pressDigits(page, '1610202606')
        await expect(toMinutes).toBeFocused()
        await expect(result).toHaveText('{"status":"unresolved"}')
        await pressDigits(page, '00')
        await expect(result).toHaveText(
          '{"status":"valid","value":[1792209600000,1792123200000]}',
        )
      },
    ],

    factors: [
      'display factors preserve the value, busy blocks keys, and controller replacement follows focus',
      async ({ page }) => {
        const errors: string[] = []
        page.on('pageerror', (error) => errors.push(error.message))
        page.on('console', (message) => {
          if (message.type() === 'error') errors.push(message.text())
        })
        await openTableFixture(
          expect,
          page,
          '/__date_time_picker/index.html',
          'date-time-picker-fixture',
        )
        const field = page.getByRole('group', {
          name: 'Starts, Europe/Warsaw',
          exact: true,
        })
        const result = page.getByTestId('fixture-result')
        const changes = page.getByTestId('fixture-changes')
        await page.getByTestId('fixture-load').click()
        await expect(result).toHaveText(
          '{"status":"valid","value":1792209600000}',
        )
        await page.getByTestId('fixture-factors').selectOption('en-US')
        const month = field.getByRole('spinbutton', {
          name: 'Month',
          exact: true,
        })
        const hours = field.getByRole('spinbutton', {
          name: 'Hours',
          exact: true,
        })
        const meridiem = field.getByRole('spinbutton', {
          name: 'AM/PM',
          exact: true,
        })
        await month.focus()
        await expect(month).toHaveValue('10')
        await page.keyboard.press('ArrowRight')
        await expect(
          field.getByRole('spinbutton', { name: 'Day', exact: true }),
        ).toBeFocused()
        await page.keyboard.press('ArrowRight')
        await expect(
          field.getByRole('spinbutton', { name: 'Year', exact: true }),
        ).toBeFocused()
        await expect(hours).toHaveValue('06')
        await expect(hours).toHaveAttribute('aria-valuemax', '12')
        await expect(meridiem).toHaveValue('AM')
        await expect(result).toHaveText(
          '{"status":"valid","value":1792209600000}',
        )
        await expect(changes).toHaveText('0')

        await page.getByTestId('fixture-busy').check()
        for (const part of await field.getByRole('spinbutton').all()) {
          await expect(part).toBeDisabled()
        }
        await hours.focus()
        await pressDigits(page, '9')
        await expect(hours).toHaveValue('06')
        await expect(result).toHaveText(
          '{"status":"valid","value":1792209600000}',
        )
        await expect(changes).toHaveText('0')

        await page.getByTestId('fixture-busy').uncheck()
        await expect(hours).toBeEnabled()
        await hours.focus()
        await expect(hours).toBeFocused()
        // selectOption changes the parent without first moving focus outside.
        await page.getByTestId('fixture-kind').selectOption('date')
        const dateField = page.getByRole('group', {
          name: 'Starts',
          exact: true,
        })
        await expect(
          dateField.getByRole('spinbutton', { name: 'Hours' }),
        ).toHaveCount(0)
        await expect(result).toHaveText('{"status":"empty"}')
        await expect(dateField.locator('input:focus')).toHaveCount(0)
        await dateField
          .getByRole('spinbutton', { name: 'Month', exact: true })
          .focus()
        await pressDigits(page, '10172026')
        await expect(result).toHaveText(
          '{"status":"valid","value":"2026-10-17"}',
        )

        const year = dateField.getByRole('spinbutton', {
          name: 'Year',
          exact: true,
        })
        await expect(year).toBeFocused()
        await page.getByTestId('fixture-count').selectOption('range')
        const fromYear = dateField.getByRole('spinbutton', {
          name: 'From Year',
          exact: true,
        })
        await expect(fromYear).toBeFocused()
        await expect(result).toHaveText('{"status":"empty"}')
        const toMonth = dateField.getByRole('spinbutton', {
          name: 'To Month',
          exact: true,
        })
        await toMonth.focus()
        await expect(toMonth).toBeFocused()
        await page.getByTestId('fixture-count').selectOption('single')
        await expect(toMonth).toHaveCount(0)
        await expect(dateField.locator('input:focus')).toHaveCount(0)
        await expect(result).toHaveText('{"status":"empty"}')
        await expect(changes).toHaveText('8')
        expect(errors).toEqual([])
      },
    ],
  }
  register(test, cases)
}

/** Send real keydowns without replacing the segmented field's native value. */
async function pressDigits(page: Page, digits: string): Promise<void> {
  for (const digit of digits) await page.keyboard.press(digit)
}
