// The poll application's single Hilos connection (the project's connection
// singleton). createHilosConnection (core) merges the framework session and page
// schemas and wires the stale-build reload; this file only states the project's
// endpoint policy.
//
// The endpoint is the same-origin /ws route in every environment: nginx proxies
// it to the daemon in test and production, and the Angular CLI dev server proxies
// it via proxy.conf.json (Angular has no import.meta.env URL-override mechanism,
// unlike the Vite demos), so no url is passed.
//
// `actions` is the requestId-correlated reply lifecycle: an admin modal such as
// the settings table's edit dialog calls `actions.dispatch(...)` and closes on
// the returned handle's resolved `done`.
import { createHilosConnection } from '@hilos/core'

export const { connection, actions } = createHilosConnection()
