// The online-testing application's single Hilos connection (the project's
// connection singleton). createHilosConnection (core) merges the framework
// session and page schemas, attaches the action-error store and the action
// lifecycle, and wires the stale-build reload; this file only states the
// project's endpoint policy.
//
// The endpoint is the same-origin /ws route in every environment: nginx proxies
// it to the daemon in test and production, and the Angular CLI dev server proxies
// it via proxy.conf.json (Angular has no import.meta.env URL-override mechanism,
// unlike the Vite demos), so no url is passed.
//
// `actions` is the requestId-correlated reply lifecycle: a modal submit calls
// `actions.dispatch(...)` and closes on the returned handle's resolved `done`.
//
// No projectSchemas: the demo declares no inbound signal of its own. The sign-in
// ones it is answered by are the framework's and are merged by
// createHilosConnection itself, like every other framework signal.
import { createHilosConnection } from '@hilos/core'

export const { connection, actions } = createHilosConnection()
