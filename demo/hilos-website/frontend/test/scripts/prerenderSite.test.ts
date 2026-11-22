// @vitest-environment node
import { Window } from 'happy-dom'
import { describe, expect, it } from 'vitest'

import { renderSite, siteOrigin } from '../../scripts/prerenderSite.js'

const TEMPLATE =
  '<!doctype html><html><head><title>Shell</title></head><body><div id="app"></div></body></html>'

describe('siteOrigin', () => {
  it('defaults to localhost and removes a trailing slash from the build origin', () => {
    expect(siteOrigin({})).toBe('https://localhost')
    expect(siteOrigin({ HILOS_SITE_ORIGIN: 'https://hilos-dev.org/' })).toBe(
      'https://hilos-dev.org',
    )
  })
})

describe('renderSite', () => {
  it('renders the home content and search metadata without browser globals', async () => {
    const files = await renderSite(TEMPLATE, 'https://hilos-dev.org')
    const window = new Window()
    window.document.write(files['home.html'])
    const document = window.document

    expect(document.querySelector('#app h1')?.textContent?.trim()).toBeTruthy()
    const names = [...document.querySelectorAll('#app article h4')].map(
      (heading) => heading.textContent?.trim(),
    )
    expect(names.sort()).toEqual([
      'BTC tracker',
      'Chat',
      'Concert tickets',
      'E-commerce shop',
      'File gallery manager',
      'Online testing',
      'PMD',
      'Polls',
      'Preferans',
      'Roblox game server',
      'Solar system model',
      'Tasks',
    ])
    expect(document.title).toBe('Real-time PHP framework · Hilos')
    const description = document
      .querySelector('meta[name="description"]')
      ?.getAttribute('content')
    expect(description?.trim()).toBeTruthy()
    expect(
      document
        .querySelector('meta[property="og:type"]')
        ?.getAttribute('content'),
    ).toBe('website')
    expect(
      document
        .querySelector('meta[property="og:site_name"]')
        ?.getAttribute('content'),
    ).toBe('Hilos')
    expect(
      document
        .querySelector('meta[property="og:title"]')
        ?.getAttribute('content'),
    ).toBe(document.title)
    expect(
      document
        .querySelector('meta[property="og:description"]')
        ?.getAttribute('content'),
    ).toBe(description)
    expect(
      document
        .querySelector('meta[property="og:url"]')
        ?.getAttribute('content'),
    ).toBe('https://hilos-dev.org/')
    expect(
      document
        .querySelector('meta[property="og:image"]')
        ?.getAttribute('content'),
    ).toBe('https://hilos-dev.org/hilos-preview-512.png')
    expect(
      document
        .querySelector('meta[property="og:image:type"]')
        ?.getAttribute('content'),
    ).toBe('image/png')
    expect(
      document
        .querySelector('meta[property="og:image:width"]')
        ?.getAttribute('content'),
    ).toBe('512')
    expect(
      document
        .querySelector('meta[property="og:image:height"]')
        ?.getAttribute('content'),
    ).toBe('512')
    expect(
      document
        .querySelector('meta[property="og:image:alt"]')
        ?.getAttribute('content')
        ?.trim(),
    ).toBeTruthy()
    expect(
      document
        .querySelector('meta[name="twitter:card"]')
        ?.getAttribute('content'),
    ).toBe('summary')
    expect(
      document.querySelector('link[rel="canonical"]')?.getAttribute('href'),
    ).toBe('https://hilos-dev.org/')
    expect(files['robots.txt']).toBe(
      'User-agent: *\nAllow: /\nSitemap: https://hilos-dev.org/sitemap.xml\n',
    )
    expect(files['sitemap.xml'].match(/<loc>[^<]+<\/loc>/g)?.sort()).toEqual([
      '<loc>https://hilos-dev.org/</loc>',
      '<loc>https://hilos-dev.org/about</loc>',
      '<loc>https://hilos-dev.org/history</loc>',
      '<loc>https://hilos-dev.org/license</loc>',
      '<loc>https://hilos-dev.org/privacy</loc>',
      '<loc>https://hilos-dev.org/terms</loc>',
    ])
    for (const filename of [
      'about.html',
      'terms.html',
      'privacy.html',
      'license.html',
      'history.html',
    ]) {
      expect(files[filename]).toContain('<div id="app"><')
    }
    expect(files['index.html']).toBeUndefined()
    expect(document.querySelector('#app a[href="/history"]')).not.toBeNull()
    await window.happyDOM.close()
  })

  it('renders the history text without browser globals', async () => {
    const files = await renderSite(TEMPLATE, 'https://hilos-dev.org')
    const window = new Window()
    window.document.write(files['history.html'])
    const document = window.document

    expect(document.title).toBe('History · Hilos')
    expect(document.querySelector('#app h1')?.textContent?.trim()).toBe(
      'History',
    )
    expect(document.querySelectorAll('#app h2')).toHaveLength(6)
    expect(document.querySelector('#app blockquote')).not.toBeNull()
    await window.happyDOM.close()
  })

  it('escapes the origin in HTML attributes and XML URLs', async () => {
    const files = await renderSite(TEMPLATE, `https://example.test/?a="<&'`)
    expect(files['home.html']).toContain(
      'href="https://example.test/?a=&quot;&lt;&amp;&#39;/"',
    )
    expect(files['home.html']).toContain(
      'content="https://example.test/?a=&quot;&lt;&amp;&#39;/hilos-preview-512.png"',
    )
    expect(files['sitemap.xml']).toContain(
      '<loc>https://example.test/?a=&quot;&lt;&amp;&#39;/</loc>',
    )
  })
})
