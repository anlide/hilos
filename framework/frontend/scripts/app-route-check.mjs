import { createRequire } from 'node:module'
import { join, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

/**
 * Execute the project route registry so its router factory validates the declarations.
 *
 * @param {string} projectRoot The Vite project root.
 * @param {string} routesFile The entry module relative to the project root.
 * @param {boolean} sourceMode Whether to load SDK sources instead of compiled exports.
 */
export async function checkAppRoutes(projectRoot, routesFile, sourceMode) {
  const root = resolve(projectRoot)
  const projectRequire = createRequire(join(root, 'package.json'))
  const vite = pathToFileURL(projectRequire.resolve('vite')).href
  const { runnerImport } = await import(vite)

  await runnerImport(resolve(root, routesFile), {
    root,
    environments: sourceMode
      ? {
          inline: {
            resolve: {
              conditions: ['development'],
              noExternal: ['@hilos/core'],
            },
          },
        }
      : undefined,
  })
}

/**
 * Validate routes at the start of the client build, after the SDK prebuild.
 *
 * @param {string} routesFile The entry module relative to the project root.
 * @returns {import('vite').Plugin} The route validation plugin.
 */
export function appRouteCheck(routesFile) {
  return {
    name: 'hilos-app-route-check',
    buildStart() {
      return checkAppRoutes(this.environment.config.root, routesFile, false)
    },
  }
}
