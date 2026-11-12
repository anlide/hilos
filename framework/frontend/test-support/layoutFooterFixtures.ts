/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const bootstrapCss = readFileSync(
  createRequire(import.meta.url).resolve('bootstrap/dist/css/bootstrap.css'),
  'utf8',
)

/** Footer-only stock visibility rules, released after native component disposal. */
export function installFooterStylesheet(): () => void {
  const stylesheet = document.head.appendChild(document.createElement('style'))
  stylesheet.textContent = bootstrapCss
  return () => stylesheet.remove()
}

/** Read actual stock CSS, including any hidden ancestor of the retained line. */
export function footerBuildIsVisible(element: HTMLElement): boolean {
  if (!element.isConnected) return false
  for (
    let node: HTMLElement | null = element;
    node;
    node = node.parentElement
  ) {
    const style = getComputedStyle(node)
    if (
      node.hidden ||
      style.display === 'none' ||
      style.visibility === 'hidden' ||
      style.visibility === 'collapse'
    )
      return false
  }
  return true
}
