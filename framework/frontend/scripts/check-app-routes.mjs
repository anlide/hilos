import process from 'node:process'

import { checkAppRoutes } from './app-route-check.mjs'

const routesFile = process.argv[2]
const option = process.argv[3]
if (
  !routesFile ||
  process.argv.length > 4 ||
  (option && option !== '--compiled')
) {
  throw new Error('Usage: node check-app-routes.mjs <routes-file> [--compiled]')
}

await checkAppRoutes(process.cwd(), routesFile, option !== '--compiled')
