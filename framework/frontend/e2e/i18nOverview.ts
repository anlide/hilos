import type { Expect, Page } from '@playwright/test'
import {
  I18N_OVERVIEW_DATA,
  i18nOverviewSchema,
  type HilosI18nOverview,
} from '../core/src/admin/i18n/hilosI18nOverview.js'
import { watchSocketFrames } from './socket.js'

/** Compare both complete tiles together, including their names and breakdown. */
export async function expectI18nOverview(
  expect: Expect,
  page: Page,
  overview: HilosI18nOverview,
): Promise<void> {
  const { languages, countries } = overview
  await expect(
    page.getByTestId(/^i18n-overview-(languages|countries)-count$/),
  ).toHaveText([
    `${languages.enabledCount} of ${languages.totalCount}`,
    `${countries.enabledCount} of ${countries.totalCount}`,
  ])
  for (const [subject, counts, caption] of [
    [
      'languages',
      languages,
      `Enabled · default ${languages.defaultLanguageName}`,
    ],
    [
      'countries',
      countries,
      `Enabled · ${countries.builtInCount} built-in, ${countries.ownCount} custom`,
    ],
  ] as const) {
    const tile = page.getByTestId(`i18n-overview-${subject}`)
    await expect(tile).toContainText(caption)
    const count = tile.getByTestId(`i18n-overview-${subject}-count`)
    await expect(count).toHaveRole('link')
    await expect(count).toHaveAttribute('href', `/hilos/i18n/${subject}`)
    await expect(count).toHaveAccessibleName(
      `${subject === 'languages' ? 'Languages' : 'Countries'}: ${counts.enabledCount} of ${counts.totalCount} enabled`,
    )
  }
  await expect(page.getByTestId('i18n-overview').getByRole('link')).toHaveCount(
    2,
  )
}

/** Observe every real root reply; a missing or partial overview stays evidence. */
export function watchI18nOverview(page: Page) {
  const replies: unknown[] = []
  const observer = watchSocketFrames(page, ({ wire }) => {
    // The socket is a JSON boundary. The schema below judges the whole overview,
    // including a root reply that omitted it, rather than filtering bad ones out.
    const message = wire as
      | {
          type?: unknown
          data?: {
            page?: unknown
            payload?: { data?: Record<string, unknown> }
          }
        }
      | undefined
    if (
      message?.type === 'page_response' &&
      message.data?.page === 'hilos_i18n'
    ) {
      replies.push(message.data.payload?.data?.[I18N_OVERVIEW_DATA])
    }
  })
  return {
    replies,
    read: () => replies.map((reply) => i18nOverviewSchema.parse(reply)),
    stop: () => observer.stop(),
  }
}
