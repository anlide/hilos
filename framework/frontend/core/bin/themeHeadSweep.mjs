import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'

/**
 * Insert the framework's first-frame theme script into every HTML page below a directory.
 *
 * @param {string} dir The built site's root.
 * @param {(html: string) => string} insert Insert or update the script.
 * @param {(html: string) => string | null} findDeferred Find a late stylesheet.
 * @returns {{ pages: number, refusals: string[] }} The page count and any build refusals.
 */
export function sweepThemeHead(dir, insert, findDeferred) {
  const refusals = []
  let pages = 0
  try {
    if (!statSync(dir).isDirectory()) {
      return { pages, refusals: ['.: not a directory'] }
    }
  } catch {
    return { pages, refusals: ['.: directory does not exist or is unreadable'] }
  }

  /** @param {string} path Directory to visit. */
  function visit(path) {
    let entries
    try {
      entries = readdirSync(path, { withFileTypes: true })
    } catch (error) {
      refusals.push(`${relative(dir, path) || '.'}: ${String(error)}`)
      return
    }

    for (const entry of entries) {
      const file = join(path, entry.name)
      if (entry.isDirectory()) {
        visit(file)
      } else if (entry.isFile() && entry.name.endsWith('.html')) {
        pages++
        try {
          const html = readFileSync(file, 'utf8')
          const updated = insert(html)
          if (updated !== html) writeFileSync(file, updated)
          if (findDeferred(html) !== null) {
            refusals.push(`${relative(dir, file)}: deferred stylesheet`)
          }
        } catch (error) {
          refusals.push(`${relative(dir, file)}: ${String(error)}`)
        }
      }
    }
  }

  visit(dir)
  if (pages === 0) refusals.push('.: no .html pages found')

  return { pages, refusals }
}
