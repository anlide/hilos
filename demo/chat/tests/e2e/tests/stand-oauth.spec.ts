import { connect } from 'node:tls'

import { test, expect, type Page } from '@playwright/test'

import { dictateGatewayBehavior, STAND_GATEWAY_URL } from '../helpers/gateway'
import {
  declareOAuthAccount,
  type StandOAuthAccount,
  type StandOAuthProfile,
} from '../helpers/oauth'
import { chooseAccount, confirmConsent, denyConsent } from '../helpers/oauth-user'

// The stand's OAuth provider, held to its contract on the provider itself (HIL-923). The
// product takes no part here: what is proved is that the emulator behaves like the thing it
// stands in for — a screen that refuses a request no provider would honor, a code good once,
// a token bound to its profile, and two providers that say the same refusal in two languages.
//
// The browser IS involved, because the consent screen is only reachable through one: the spec
// opens the screen itself with an authorization request of its own making, and reads the code
// and the state off the address the provider's redirect then carried it to. A client that owns
// that address is the next leaf's business, not this one's — here nothing has to be there, and
// the 404 the browser meets is read by nobody.
//
// WHERE that address points is not free, though, and two ways of arranging it were measured on
// 2026-09-17 and rejected. A name that resolves nowhere, answered by page.route(): the redirect
// of a navigation nobody intercepted is followed by the network stack without consulting the
// route table, so the browser really goes looking and really fails (ERR_NAME_NOT_RESOLVED). The
// same name with the request watched instead of the landing: the assertion passes, but the
// failed navigation stays in flight and interrupts the next screen this spec asks for, which
// made four tests out of nine flaky. An address on the gateway's own origin ends the redirect
// with an ordinary answer, and nothing is left pending.
//
// /test/reset is never called here — it would wipe what the other workers declared. Isolation
// rests on an account id nobody else holds.

/** Name the gateway's certificate is issued for, which a raw TLS connection has to ask for. */
const GATEWAY_SERVER_NAME = 'stand-gateway'

/** Path the provider sends the browser back to; no route serves it, so the browser gets a 404. */
const CALLBACK_PATH = '/oauth-callback'

/** The whole callback address, which the exchange has to quote back exactly as the screen carried it. */
const CALLBACK_URL = `${STAND_GATEWAY_URL}${CALLBACK_PATH}`

/** Client the spec asks for access as. */
const CLIENT_ID = 'stand-oauth-client'

/** Secret the spec exchanges a code with; the emulator checks only that there is one. */
const CLIENT_SECRET = 'stand-oauth-secret'

/** Access the spec asks for, in GitHub's own words. */
const SCOPE = 'read:user user:email'

/** User-Agent the spec sends where a real client would send its own. */
const USER_AGENT = 'hilos-stand-spec'

/** What one call of the emulator came back with. */
interface Answer {
  status: number
  body: string
}

/**
 * Build the authorization request the browser is sent to, as a path on the gateway.
 *
 * A path and not the whole address, so that every caller writes the address from
 * STAND_GATEWAY_URL itself: that is how E2E-PAGE-GOTO tells a stand screen from a page of
 * the product, and the one navigation it lets past gotoPage().
 *
 * @param profile The provider to ask.
 * @param overrides What to say differently from a well-formed request.
 * @returns The path of the consent screen, query included.
 */
function authorizePath(
  profile: StandOAuthProfile,
  overrides: Record<string, string> = {},
): string {
  const query = new URLSearchParams({
    client_id: CLIENT_ID,
    redirect_uri: CALLBACK_URL,
    scope: SCOPE,
    response_type: 'code',
    ...overrides,
  })

  return `/oauth/${profile}/authorize?${query.toString()}`
}

/**
 * Open the consent screen.
 *
 * @param page The browser page standing in for the window the product would open.
 * @param profile The provider to ask.
 * @param overrides What to say differently from a well-formed request.
 */
async function openScreen(
  page: Page,
  profile: StandOAuthProfile,
  overrides: Record<string, string> = {},
): Promise<void> {
  await page.goto(`${STAND_GATEWAY_URL}${authorizePath(profile, overrides)}`)
}

/**
 * Press a button on the screen and read where the provider then sent the browser.
 *
 * A landing is recognized by its PATH and never by a pattern over the whole address: an
 * authorization request carries the callback inside itself, url-encoded, so a looser match
 * answers with the consent screen's own url the moment it is asked.
 *
 * @param page The browser page standing in for the provider's window.
 * @param press What the person does on the screen.
 * @returns The query the provider sent to the callback address.
 */
async function followRedirect(
  page: Page,
  press: () => Promise<void>,
): Promise<URLSearchParams> {
  await press()
  await page.waitForURL((url) => url.pathname === CALLBACK_PATH)

  return new URL(page.url()).searchParams
}

/**
 * Walk a person through the screen and grant access, returning what came back.
 *
 * @param page The browser page standing in for the provider's window.
 * @param account The account to sign in as.
 * @param state The client's own opaque string.
 * @returns The query the provider sent to the callback address.
 */
async function grantAccess(
  page: Page,
  account: StandOAuthAccount,
  state: string,
): Promise<URLSearchParams> {
  await openScreen(page, account.profile, { state })
  await chooseAccount(page, account)

  return await followRedirect(page, () => confirmConsent(page))
}

/**
 * Grant access and read the code out of the callback, for a spec that only needs a code.
 *
 * @param page The browser page standing in for the provider's window.
 * @param account The account to sign in as.
 * @returns The authorization code the provider issued.
 */
async function codeFor(page: Page, account: StandOAuthAccount): Promise<string> {
  const callback = await grantAccess(page, account, `state-${account.subject}`)

  return callback.get('code') ?? ''
}

/**
 * Exchange a code for a token the way a daemon does.
 *
 * @param profile The provider to exchange at.
 * @param overrides What to say differently from a well-formed exchange.
 * @param wantsJson Whether to ask for JSON, as the framework's OAuth client does.
 * @returns The status and the body, read as text so a form answer can be asserted too.
 */
async function exchange(
  profile: StandOAuthProfile,
  overrides: Record<string, string>,
  wantsJson = true,
): Promise<Answer> {
  const response = await fetch(`${STAND_GATEWAY_URL}/oauth/${profile}/token`, {
    method: 'POST',
    headers: wantsJson ? { Accept: 'application/json' } : {},
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: CLIENT_ID,
      client_secret: CLIENT_SECRET,
      redirect_uri: CALLBACK_URL,
      ...overrides,
    }),
  })

  return { status: response.status, body: await response.text() }
}

/**
 * Read an answer's body as the object the provider says it is.
 *
 * @param answer The answer to read.
 * @returns The decoded body.
 */
function payloadOf(answer: Answer): Record<string, unknown> {
  return JSON.parse(answer.body) as Record<string, unknown>
}

/**
 * Exchange a code and take the token out of the answer, failing the spec if there is none.
 *
 * @param profile The provider to exchange at.
 * @param code The authorization code to spend.
 * @returns The access token.
 */
async function tokenFor(
  profile: StandOAuthProfile,
  code: string,
): Promise<string> {
  const token = payloadOf(await exchange(profile, { code })).access_token
  expect(token, `the ${profile} exchange answered no token`).toBeTruthy()

  return String(token)
}

/**
 * Read the account behind a token the way a daemon does.
 *
 * @param profile The provider to ask.
 * @param token The access token.
 * @returns The status and the body.
 */
async function readUserInfo(
  profile: StandOAuthProfile,
  token: string,
): Promise<Answer> {
  const response = await fetch(`${STAND_GATEWAY_URL}/oauth/${profile}/userinfo`, {
    headers: { Authorization: `Bearer ${token}`, 'User-Agent': USER_AGENT },
  })

  return { status: response.status, body: await response.text() }
}

/**
 * Send one request over a raw TLS connection and read every byte of the answer.
 *
 * Raw rather than fetch, because what is being shown is a request carrying NO User-Agent at
 * all, and an HTTP client puts one in whether it is asked to or not.
 *
 * @param lines The request, header lines and all.
 * @returns The whole answer, status line included.
 */
function requestOverTls(lines: string[]): Promise<string> {
  const { hostname, port } = new URL(STAND_GATEWAY_URL)

  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = []
    const socket = connect(
      { host: hostname, port: Number(port), servername: GATEWAY_SERVER_NAME },
      () => socket.write([...lines, '', ''].join('\r\n')),
    )

    socket.on('data', (chunk: Buffer) => chunks.push(chunk))
    socket.on('error', reject)
    socket.on('close', () => resolve(Buffer.concat(chunks).toString()))
  })
}

/**
 * Read the account behind a token without sending a User-Agent header.
 *
 * @param profile The provider to ask.
 * @param token The access token.
 * @returns The whole answer, status line included.
 */
function readUserInfoBare(
  profile: StandOAuthProfile,
  token: string,
): Promise<string> {
  return requestOverTls([
    `GET /oauth/${profile}/userinfo HTTP/1.1`,
    `Host: ${GATEWAY_SERVER_NAME}`,
    `Authorization: Bearer ${token}`,
    'Connection: close',
  ])
}

/**
 * Declare an account without failing on a refusal, so a spec can assert it.
 *
 * @param declaration The declaration as the gateway reads it.
 * @returns The status and the refusal code, if any.
 */
async function declare(
  declaration: Record<string, unknown>,
): Promise<{ status: number; error: string | undefined }> {
  const response = await fetch(`${STAND_GATEWAY_URL}/oauth/test/account`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(declaration),
  })
  const payload = (await response.json()) as { error?: string }

  return { status: response.status, error: payload.error }
}

test('signs a person in as the GitHub account a spec declared', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github', {
    name: 'Ada Lovelace',
    email: 'ada@e2e.stand',
  })
  const state = `state-${account.subject}`

  const callback = await grantAccess(page, account, state)
  expect(callback.get('state')).toBe(state)

  const code = callback.get('code') ?? ''
  expect(code).toBeTruthy()

  const userInfo = await readUserInfo('github', await tokenFor('github', code))
  expect(userInfo.status).toBe(200)

  const payload = payloadOf(userInfo)
  expect(payload).toEqual({
    login: account.login,
    id: Number(account.subject),
    name: 'Ada Lovelace',
    email: 'ada@e2e.stand',
  })
  // A JSON number and not a string of digits: that is how GitHub carries its id, and a
  // product reading it as a string would pass here and fail in production.
  expect(typeof payload.id).toBe('number')
})

test('says "this account has no email" the way each provider says it', async ({
  page,
}) => {
  const atGoogle = await declareOAuthAccount('google', { name: 'No Mail' })
  const atGitHub = await declareOAuthAccount('github', { name: 'No Mail' })

  const googleToken = await tokenFor('google', await codeFor(page, atGoogle))
  const google = payloadOf(await readUserInfo('google', googleToken))

  expect(google.sub).toBe(atGoogle.subject)
  expect(typeof google.sub).toBe('string')
  expect('email' in google).toBe(false)

  const gitHubToken = await tokenFor('github', await codeFor(page, atGitHub))
  const github = payloadOf(await readUserInfo('github', gitHubToken))

  expect('email' in github).toBe(true)
  expect(github.email).toBeNull()
})

test('sends a refusal back to the client when the person says no', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github')
  const state = `state-${account.subject}`

  await openScreen(page, 'github', { state })
  await chooseAccount(page, account)

  const callback = await followRedirect(page, () => denyConsent(page))
  expect(callback.get('error')).toBe('access_denied')
  expect(callback.get('state')).toBe(state)
  expect(callback.get('code')).toBeNull()
})

test('spends an authorization code exactly once', async ({ page }) => {
  const atGitHub = await declareOAuthAccount('github')
  const gitHubCode = await codeFor(page, atGitHub)
  await tokenFor('github', gitHubCode)

  // GitHub answers a refused exchange with a 200 and writes the refusal where the token
  // would have been; Google answers a status of its own.
  const gitHubAgain = await exchange('github', { code: gitHubCode })
  expect(gitHubAgain.status).toBe(200)
  expect(payloadOf(gitHubAgain)).toMatchObject({ error: 'bad_verification_code' })

  const atGoogle = await declareOAuthAccount('google')
  const googleCode = await codeFor(page, atGoogle)
  await tokenFor('google', googleCode)

  const googleAgain = await exchange('google', { code: googleCode })
  expect(googleAgain.status).toBe(400)
  expect(payloadOf(googleAgain)).toMatchObject({ error: 'invalid_grant' })
})

test('refuses an exchange that does not match the consent it quotes', async ({
  page,
}) => {
  const atGitHub = await declareOAuthAccount('github')
  const atGoogle = await declareOAuthAccount('google')

  // A fresh code per refusal: the exchange spends the code the moment it recognizes it,
  // refused or not.
  const elsewhere = await exchange('github', {
    code: await codeFor(page, atGitHub),
    redirect_uri: 'https://elsewhere.stand/cb',
  })
  expect(payloadOf(elsewhere)).toMatchObject({ error: 'redirect_uri_mismatch' })

  const noSecret = await exchange('github', {
    code: await codeFor(page, atGitHub),
    client_secret: '',
  })
  expect(payloadOf(noSecret)).toMatchObject({
    error: 'incorrect_client_credentials',
  })

  const noSecretAtGoogle = await exchange('google', {
    code: await codeFor(page, atGoogle),
    client_secret: '',
  })
  expect(noSecretAtGoogle.status).toBe(401)
  expect(payloadOf(noSecretAtGoogle)).toMatchObject({ error: 'invalid_client' })
})

test('demands a User-Agent where the real provider demands one', async ({
  page,
}) => {
  const atGitHub = await declareOAuthAccount('github')
  const atGoogle = await declareOAuthAccount('google')
  const gitHubToken = await tokenFor('github', await codeFor(page, atGitHub))
  const googleToken = await tokenFor('google', await codeFor(page, atGoogle))

  const refused = await readUserInfoBare('github', gitHubToken)
  expect(refused).toContain('HTTP/1.1 403')
  expect(refused).toContain('Content-Type: text/plain')
  expect(refused).toContain('User-Agent header')

  // Google asks for no such thing, and the very same request is answered as usual.
  const answered = await readUserInfoBare('google', googleToken)
  expect(answered).toContain('HTTP/1.1 200')
  expect(answered).toContain(`"sub":"${atGoogle.subject}"`)
})

test('carries the house levers on both provider routes, keyed by the account', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github')
  const code = await codeFor(page, account)

  await dictateGatewayBehavior('/oauth/github/token', account.subject, {
    status: 500,
  })
  const dictated = await exchange('github', { code })
  expect(dictated.status).toBe(500)
  expect(payloadOf(dictated)).toMatchObject({ error: 'STATUS_DICTATED' })

  // The code survives a dictated refusal: the lever answers instead of the resident, so
  // nothing was spent. Working out the key is a peek at the code, never a take.
  const token = await tokenFor('github', code)

  await dictateGatewayBehavior('/oauth/github/userinfo', account.subject, {
    status: 500,
  })
  expect((await readUserInfo('github', token)).status).toBe(500)
  expect((await readUserInfo('github', token)).status).toBe(200)
})

test('refuses to be a consent screen for a request no provider would honor', async () => {
  const refusals: [Record<string, string>, string][] = [
    [{ client_id: '' }, 'client_id'],
    [{ redirect_uri: 'not-an-address' }, 'redirect_uri'],
    [{ redirect_uri: 'ftp://oauth-callback.stand/cb' }, 'redirect_uri'],
    [{ response_type: 'token' }, 'response_type'],
  ]

  for (const [overrides, parameter] of refusals) {
    const response = await fetch(
      `${STAND_GATEWAY_URL}${authorizePath('github', overrides)}`,
      { redirect: 'manual' },
    )

    expect(response.status, JSON.stringify(overrides)).toBe(400)
    expect(await response.text()).toContain(
      `<p data-id="oauth-error">${parameter}</p>`,
    )
  }
})

test('refuses an account declaration it cannot honor', async () => {
  const subject = (await declareOAuthAccount('github')).subject
  const refusals: [Record<string, unknown>, string][] = [
    [{ profile: 'github', subject, nickname: 'ada' }, 'FIELD_UNKNOWN'],
    [{ profile: 'gitlab', subject }, 'PROFILE_UNKNOWN'],
    [{ profile: 'github', subject: 'ada' }, 'SUBJECT_REQUIRED'],
    [{ profile: 'github', subject: '' }, 'SUBJECT_REQUIRED'],
    [{ profile: 'github', subject, login: 42 }, 'FIELD_INVALID'],
  ]

  for (const [declaration, error] of refusals) {
    expect(await declare(declaration), JSON.stringify(declaration)).toEqual({
      status: 400,
      error,
    })
  }
})
