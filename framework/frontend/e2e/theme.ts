import type { BrowserContext } from '@playwright/test'

// The theme a page wears comes from the account's pick once signed in, and before
// that from the browser's own (docs/agents/architecture/theme.md). A kit whose
// header draws the theme menu picks a theme through it; a kit that has no menu
// yet — React until HIL-1440, Angular until HIL-1441 — can only set the
// browser's pick before the page loads, and let registration carry it into the
// account. The Vue kit keeps picking through its menu, as the spec of the
// section does today.

/**
 * The storage key of the browser's theme pick, copied verbatim from
 * THEME_PICK_STORAGE_KEY in core/src/theme/themeStored.ts. The e2e package builds
 * against no framework source, so the literal is kept beside the step that
 * writes it.
 */
const THEME_PICK_STORAGE_KEY = 'hilos.theme.pick'

/**
 * Choose the theme every page of the context will wear, before any of them
 * loads. Call it before signing up: registration adopts the browser's pick into
 * the account (demo/tasks/tests/e2e/tests/daemon.spec.ts), so every tab opened
 * in the context afterwards wears it too, with no menu involved.
 *
 * @param context the browser context whose pages are yet to load.
 * @param theme the position every page of the context will wear.
 */
export async function pickThemeBeforeLoad(
  context: BrowserContext,
  theme: 'light' | 'dark',
): Promise<void> {
  await context.addInitScript(
    ({ key, pick }: { key: string; pick: string }) => {
      localStorage.setItem(key, pick)
    },
    { key: THEME_PICK_STORAGE_KEY, pick: theme },
  )
}
