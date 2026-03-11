/**
 * Post-build script: reorganize vite-ssg output for daemon (path/index.{en,ru}.html),
 * generate robots.txt and sitemap.xml.
 */
import { readdirSync, readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'

const distDir = join(process.cwd(), 'dist')
const locales = ['en', 'ru']
const baseUrl = process.env.VITE_APP_URL || 'https://example.com'

function processHtmlFile(filePath, relPath) {
  const html = readFileSync(filePath, 'utf-8')
  const pathWithoutExt = relPath.replace(/\.html$/, '')
  if (pathWithoutExt === 'index' || pathWithoutExt === '') {
    for (const locale of locales) {
      writeFileSync(join(distDir, `index.${locale}.html`), html)
    }
    return
  }
  const targetDir = join(distDir, pathWithoutExt)
  mkdirSync(targetDir, { recursive: true })
  for (const locale of locales) {
    writeFileSync(join(targetDir, `index.${locale}.html`), html)
  }
}

function collectHtmlFiles(dir, base = '') {
  const entries = readdirSync(dir, { withFileTypes: true })
  const results = []
  for (const entry of entries) {
    const fullPath = join(dir, entry.name)
    const relPath = base ? `${base}/${entry.name}` : entry.name
    if (entry.isDirectory()) {
      if (entry.name !== 'assets' && entry.name !== '.vite') {
        results.push(...collectHtmlFiles(fullPath, relPath))
      }
    } else if (entry.name.endsWith('.html') && !entry.name.match(/^index\.(en|ru)\.html$/)) {
      results.push({ fullPath, relPath })
    }
  }
  return results
}

function main() {
  if (!existsSync(distDir)) {
    console.error('dist/ directory not found. Run build first.')
    process.exit(1)
  }

  const htmlFiles = collectHtmlFiles(distDir)
  for (const { fullPath, relPath } of htmlFiles) {
    processHtmlFile(fullPath, relPath)
  }

  const PUBLIC_PAGES = ['', 'privacy', 'terms', 'licence', 'agents']
  const urls = PUBLIC_PAGES.map((path) => {
    const loc = path ? `${baseUrl}/${path}` : baseUrl
    return `  <url>
    <loc>${loc}</loc>
    <changefreq>monthly</changefreq>
    <priority>${path ? '0.8' : '1.0'}</priority>
  </url>`
  }).join('\n')
  const sitemap = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls}
</urlset>`
  writeFileSync(join(distDir, 'sitemap.xml'), sitemap)

  const robots = `User-agent: *
Allow: /

Sitemap: ${baseUrl}/sitemap.xml
`
  writeFileSync(join(distDir, 'robots.txt'), robots)

  console.log('Post-build: reorganized HTML, created sitemap.xml, robots.txt')
}

main()
