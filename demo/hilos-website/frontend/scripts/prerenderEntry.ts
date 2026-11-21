// This is the only demo with a public home page: / is prerendered into
// home.html (HIL-2186). index.html remains the SPA shell for deep links.
// Search-engine URLs use the origin supplied by the build environment.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { renderSite, siteOrigin } from './prerenderSite.js'

const distDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'dist')
const template = readFileSync(join(distDir, 'index.html'), 'utf8')
const files = await renderSite(template, siteOrigin(process.env))

for (const [filename, html] of Object.entries(files)) {
  writeFileSync(join(distDir, filename), html)
}
