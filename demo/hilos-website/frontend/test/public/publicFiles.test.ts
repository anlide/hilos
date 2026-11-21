// @vitest-environment node
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Window } from 'happy-dom'
import { describe, expect, it } from 'vitest'

import { renderSite } from '../../scripts/prerenderSite.js'

const publicDir = fileURLToPath(new URL('../../public', import.meta.url))
const indexPath = fileURLToPath(new URL('../../index.html', import.meta.url))
const indexTemplate = readFileSync(indexPath, 'utf-8')

describe('public static assets', () => {
  it('ensures all icon links in index.html exist in public/', async () => {
    const window = new Window()
    window.document.write(indexTemplate)

    const iconLinks = [
      ...window.document.querySelectorAll(
        'link[rel="icon"], link[rel="apple-touch-icon"]',
      ),
    ]
    expect(iconLinks).toHaveLength(3)

    for (const link of iconLinks) {
      const href = link.getAttribute('href')
      expect(href).toBeTruthy()
      expect(href?.startsWith('/')).toBe(true)
      const relativePath = href!.replace(/^\//, '')
      expect(existsSync(join(publicDir, relativePath))).toBe(true)
    }

    await window.happyDOM.close()
  })

  it('ensures all prerendered home images and preview image exist in public/', async () => {
    const files = await renderSite(indexTemplate, 'https://hilos-dev.org')
    const homeHtml = files['home.html']
    expect(homeHtml).toBeTruthy()

    const window = new Window()
    window.document.write(homeHtml)

    const images = [...window.document.querySelectorAll('#app img')]
    expect(images).toHaveLength(12)

    for (const img of images) {
      const src = img.getAttribute('src')
      expect(src).toBeTruthy()
      expect(src?.startsWith('/')).toBe(true)
      const relativePath = src!.replace(/^\//, '')
      expect(existsSync(join(publicDir, relativePath))).toBe(true)
    }

    const ogImage = window.document
      .querySelector('meta[property="og:image"]')
      ?.getAttribute('content')
    expect(ogImage).toBe('https://hilos-dev.org/hilos-preview-512.png')
    const ogImageUrl = new URL(ogImage!)
    const ogImagePath = ogImageUrl.pathname.replace(/^\//, '')
    expect(existsSync(join(publicDir, ogImagePath))).toBe(true)

    await window.happyDOM.close()
  })
})
