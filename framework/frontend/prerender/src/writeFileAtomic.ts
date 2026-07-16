// Write a file by writing a temp sibling and renaming it into place, so a reader
// — nginx serving a public page, or a runtime CMS overwrite (HIL-232) — never
// sees a truncated file: a rename within a directory is atomic (HIL-211 Flow Q2).

import { renameSync, rmSync, writeFileSync } from 'node:fs'
import process from 'node:process'

// Distinguishes concurrent writers' temp siblings — a build process and a runtime
// render can target the same directory. Combined with the pid, unique per write.
let tempSequence = 0

/**
 * Atomically write `contents` to `filePath` through a temp sibling and rename.
 * On a failed rename the temp sibling is removed, so no `.tmp` file is left
 * behind.
 *
 * @param filePath The destination path.
 * @param contents The file contents.
 */
export function writeFileAtomic(filePath: string, contents: string): void {
  const tempPath = `${filePath}.${process.pid}.${tempSequence++}.tmp`
  writeFileSync(tempPath, contents)
  try {
    renameSync(tempPath, filePath)
  } catch (error) {
    rmSync(tempPath, { force: true })
    throw error
  }
}
