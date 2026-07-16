// The canonical site origin (HILOS_SITE_ORIGIN) used by the SEO artifacts —
// robots.txt, sitemap.xml, canonical URLs (HIL-214) — and any absolute URL the
// prerender emits. Read from the environment at build and runtime, with a
// localhost default so a project needs no configuration to build (HIL-211
// Design Q4).

import process from 'node:process'

/** The environment variable holding the canonical site origin. */
const SITE_ORIGIN_ENV = 'HILOS_SITE_ORIGIN'

/** The origin used when `HILOS_SITE_ORIGIN` is unset (dev, preview, tests). */
const DEFAULT_SITE_ORIGIN = 'https://localhost'

/**
 * Resolve the canonical site origin from the environment.
 *
 * @param env The environment to read; defaults to `process.env`.
 */
export function resolveSiteOrigin(
  env: Record<string, string | undefined> = process.env,
): string {
  return env[SITE_ORIGIN_ENV] ?? DEFAULT_SITE_ORIGIN
}
