import { test, expect } from '@playwright/test'

test.describe('SEO and static pages', () => {
  test('HTTP redirects to HTTPS', async ({ page }) => {
    const baseURL = process.env.BASE_URL || 'https://localhost:443'
    const httpURL =
      process.env.BASE_HTTP_URL ||
      baseURL.replace('https://', 'http://').replace(':443', ':80')
    const res = await page.goto(httpURL, { waitUntil: 'commit' })
    expect(res?.url()).toMatch(/^https:/)
    expect(res?.status()).toBe(200)
  })

  test('trailing slash redirects to no-trailing-slash', async ({ page }) => {
    const res = await page.goto('/privacy/')
    expect(res?.url()).toMatch(/\/privacy$/)
    expect(res?.status()).toBe(200)
  })

  test('prerendered content on /privacy', async ({ page }) => {
    await page.goto('/privacy')
    await expect(page.getByRole('heading', { name: /privacy/i })).toBeVisible()
  })

  test('prerendered content on /terms', async ({ page }) => {
    await page.goto('/terms')
    await expect(page.getByRole('heading', { name: /terms/i })).toBeVisible()
  })

  test('admin without auth returns 403 HTML', async ({ request }) => {
    const res = await request.get('/admin')
    expect(res.status()).toBe(403)
    const body = await res.text()
    expect(body).toMatch(/access denied/i)
  })

  test('nonexistent path returns 404 HTML', async ({ request }) => {
    const res = await request.get('/nonexistent-page-xyz')
    expect(res.status()).toBe(404)
    const body = await res.text()
    expect(body).toMatch(/page not found|does not exist|404/i)
  })

  test('home has noscript fallback', async ({ page }) => {
    await page.goto('/')
    const noscript = page.locator('noscript')
    await expect(noscript).toBeAttached()
    expect(await noscript.textContent()).toMatch(/enable JavaScript|javascript/i)
  })

  test('Accept-Language header is honored', async ({ page, context }) => {
    await context.setExtraHTTPHeaders({ 'Accept-Language': 'ru' })
    await page.goto('/privacy')
    await expect(page.getByRole('heading', { name: /privacy/i })).toBeVisible()
  })

  test('robots.txt is served', async ({ request }) => {
    const res = await request.get('/robots.txt')
    expect(res.status()).toBe(200)
    expect(await res.text()).toMatch(/User-agent|sitemap/i)
  })

  test('sitemap.xml is served', async ({ request }) => {
    const res = await request.get('/sitemap.xml')
    expect(res.status()).toBe(200)
    const text = await res.text()
    expect(text).toMatch(/xml|urlset|loc/i)
  })
})
