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
import {
  AUTH_CODE_SIGNAL_SCHEMAS,
  AUTH_CONVERGE_SIGNAL_SCHEMAS,
  createHilosConnection,
  OAUTH_SIGNAL_SCHEMAS,
  PASSKEY_SIGNAL_SCHEMAS,
} from '@hilos/core'

import { GUEST_SIGNAL_SCHEMAS } from './guest'

export const { connection, actions } = createHilosConnection({
  // The inbound signals this project mounts: the display name of a session that
  // carries no account (HIL-611), plus the four the framework auth surface is
  // answered by — the OAuth login start-reply and failure/timeout (HIL-281), the
  // passkey ceremony options (HIL-284), the phone code-request outcome (HIL-492)
  // and the auth-converge step change (HIL-415).
  //
  // Every one of them is an outcome that does NOT ride the reply to the action
  // that asked for it: the work is done by an agent and reported when it settles.
  // A schema left out here is not a degraded feature but a silent hang - the
  // frame is dropped at the parse boundary and the surface waits forever on a
  // button that never stops loading.
  projectSchemas: {
    ...GUEST_SIGNAL_SCHEMAS,
    ...AUTH_CODE_SIGNAL_SCHEMAS,
    ...AUTH_CONVERGE_SIGNAL_SCHEMAS,
    ...OAUTH_SIGNAL_SCHEMAS,
    ...PASSKEY_SIGNAL_SCHEMAS,
  },
})
