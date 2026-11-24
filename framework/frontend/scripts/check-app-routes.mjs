import process from 'node:process'

import { checkAppRoutes } from './app-route-check.mjs'

const routesFile = process.argv[2]
if (!routesFile) {
  throw new Error('Usage: node check-app-routes.mjs <routes-file>')
}

await checkAppRoutes(process.cwd(), routesFile, true)
