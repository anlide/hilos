// The tasks demo's HilosAuthContext: binds the framework sign-in surface
// (@hilos/react HilosAuthSurface) to this project's connection, scope stores, and
// action lifecycle, and declares the ways in this deployment offers. The framework
// owns the machine, the wire, the screens and the copy; the project supplies only
// where the data lives and what is enabled — and, on its backend, the handlers.
//
// The method registry is the extension point that replaces flags: the surface is
// method-agnostic (HIL-423), so a deployment differs by the descriptors listed
// here. HIL-427 replaces these literals with the settings-owned set.
import {
  createHilosAuthContext,
  MAGIC_LINK_FLOW_METHOD,
  oauthFlowMethod,
  PASSKEY_FLOW_METHOD,
  PASSWORD_FLOW_METHOD,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  type AuthFlowMethodDescriptor,
  type CodeChannelDescriptor,
  type HilosAuthContext,
} from '@hilos/core'

import { actions, connection } from '../bootstrap/connection'
import { scopes } from '../bootstrap/session'
import { OAUTH_PROVIDERS } from './oauthProviders'

/** Where this deployment serves the terms the consent screen links to. */
const TERMS_PATH = '/terms'

/** Where this deployment serves the privacy policy the consent screen links to. */
const PRIVACY_PATH = '/privacy'

// The project's ORDERED enabled methods — the extension point the machine takes
// as data. The identifier method comes first (it owns the shared field), then the
// icon methods in the order their buttons appear; the providers come from this
// project's own declaration, since it is the project that wired the credentials.
const methods: readonly AuthFlowMethodDescriptor[] = [
  PASSWORD_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  MAGIC_LINK_FLOW_METHOD,
  ...OAUTH_PROVIDERS.map((provider) =>
    oauthFlowMethod(provider.key, provider.label),
  ),
]

// The project's ORDERED code delivery channels (HIL-492), declared the same way
// and for the same reason: adding one is a descriptor here and a setting on the
// backend, never an edit inside the surface.
const channels: readonly CodeChannelDescriptor[] = [
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
]

/** This project's context for the framework sign-in surface. */
export const hilosAuthContext: HilosAuthContext = createHilosAuthContext({
  connection,
  scopes,
  actions,
  methods,
  channels,
  oauthProviders: OAUTH_PROVIDERS,
  termsPath: TERMS_PATH,
  privacyPath: PRIVACY_PATH,
})
