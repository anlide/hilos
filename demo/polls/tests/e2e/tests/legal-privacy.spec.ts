import { expect, test } from '@playwright/test'

import {
  legalAgreementsStateSchema,
  type HilosLegalAgreementsState,
} from '../../../../../framework/frontend/core/src/legal/legalAgreements.js'
import { pageResponseSchema } from '../../../../../framework/frontend/core/src/protocol/scopePayload.js'
import {
  logout,
  signUp,
  watchClientDocument,
  watchSocketFrames,
  clickSubmit,
  gotoPage,
  PAGE_READY,
} from '../../../../../framework/frontend/e2e/index.js'
import { holdLegalRevision } from '../helpers/legalHold.js'

// Privacy uses polls's existing substantial analytics revision. Holds change the
// real acceptance records; page replies and the reader follow over the socket.
const CURRENT_PRIVACY = '2026-10-09'
const HELD_PRIVACY = '2026-09-17'
const HELD_TERMS = '2026-10-01'
const CONSENT_REVISED =
  'The terms were updated while you were reading. Review the differences and accept again.'

test('a guest reads the published policy without an acceptance action', async ({
  page,
}) => {
  await gotoPage(expect, page, '/privacy', PAGE_READY)
  const reader = page.getByTestId('privacy-reader')
  await expect(reader).toHaveAttribute('data-state', 'guest')
  await expect(reader.getByTestId('legal-document-reader-line')).toHaveText(
    'Revision in force since 9 October 2026. Without an account there is nothing to accept.',
  )
  const text = page.getByTestId('privacy-text')
  await expect(text.getByTestId('legal-revision-clause')).toHaveCount(7)
  await expect(text.getByTestId('legal-revision-clause-deviation')).toHaveCount(
    1,
  )
  await expect(text).toContainText(
    'Account deletion leaves numbered analytics events and network addresses',
  )
  await expect(reader.getByTestId('legal-document-accept')).toHaveCount(0)
  await expect(reader.getByTestId('legal-document-changes-open')).toHaveCount(0)
  await expect(page.getByTestId('privacy-erase')).toBeEnabled()
})

test('sign-up coverage becomes a guest reader on sign-out in the same page', async ({
  page,
}) => {
  await signUp(expect, page)
  await gotoPage(expect, page, '/privacy', PAGE_READY)
  const document = await watchClientDocument(page)
  const reader = page.getByTestId('privacy-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await expect(reader).toContainText(
    'You accepted the revision of 9 October 2026',
  )
  await expect(reader.getByTestId('legal-document-accept')).toHaveCount(0)

  await logout(expect, page)
  await expect(reader).toHaveAttribute('data-state', 'guest')
  await expect(reader).not.toContainText('You accepted')
  await expect(page.getByTestId('privacy-changes-modal')).toHaveCount(0)
  await document.unchanged()
  await document.dispose()
})

test('an open policy follows a hold and accepts only Privacy through its comparison', async ({
  page,
}) => {
  const snapshots: HilosLegalAgreementsState[] = []
  watchSocketFrames(page, ({ wire }) => {
    if (
      wire === null ||
      typeof wire !== 'object' ||
      !('type' in wire) ||
      wire.type !== 'page_response' ||
      !('data' in wire)
    )
      return
    const reply = pageResponseSchema.parse(wire.data)
    if (reply.page !== 'hilos_privacy') return
    const agreements = reply.payload?.data?.legalAgreements
    if (agreements !== undefined)
      snapshots.push(legalAgreementsStateSchema.parse(agreements))
  })
  const person = await signUp(expect, page)
  await gotoPage(expect, page, '/privacy', PAGE_READY)
  const document = await watchClientDocument(page)
  const reader = page.getByTestId('privacy-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')

  // Terms is behind its editorial revision: accepting Terms accidentally would
  // change held and accepted even though its standing is already covered.
  const termsHold = await holdLegalRevision(person.userId, 'terms', HELD_TERMS)
  expect(termsHold.standing).toBe('covered')
  const held = await holdLegalRevision(person.userId, 'privacy', HELD_PRIVACY)
  expect(held.standing).toBe('lapsed')
  expect(held.frozen).toBe(true)
  await expect(reader).toHaveAttribute('data-state', 'due')
  await expect(reader.getByTestId('legal-document-reader-plate')).toContainText(
    'account frozen · deadline passed 9 October 2026',
  )
  await expect
    .poll(() => snapshots.at(-1)?.documents)
    .toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          document: 'terms',
          held: expect.objectContaining({ revisionId: HELD_TERMS }),
          standing: 'covered',
        }),
        expect.objectContaining({
          document: 'privacy',
          held: expect.objectContaining({ revisionId: HELD_PRIVACY }),
          standing: 'lapsed',
        }),
      ]),
    )
  const termsBefore = snapshots
    .at(-1)
    ?.documents.find((item) => item.document === 'terms')
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)

  await clickSubmit(expect, reader.getByTestId('legal-document-changes-open'))
  const comparison = page.getByTestId('privacy-changes-modal')
  await expect(comparison).toBeVisible()
  const wide = comparison.getByTestId('legal-changes-wide')
  await expect(wide).toBeVisible()
  await expect(wide.getByTestId('legal-change-row')).toHaveCount(1)
  await expect(wide.getByTestId('legal-change-kind')).toHaveText('changed')
  await expect(wide).toContainText('standard.deletion')
  await expect(wide).toContainText('Hilos standard text')
  await expect(wide).toContainText('Project deviation')
  await expect(wide).toContainText(
    'numbered analytics events and network addresses',
  )

  await clickSubmit(expect, page.getByTestId('privacy-changes-accept'))
  await expect(comparison).toHaveCount(0)
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await expect
    .poll(() =>
      snapshots.at(-1)?.documents.find((item) => item.document === 'privacy'),
    )
    .toMatchObject({
      current: { revisionId: CURRENT_PRIVACY },
      held: { revisionId: CURRENT_PRIVACY },
      standing: 'covered',
    })
  expect(
    snapshots.at(-1)?.documents.find((item) => item.document === 'terms'),
  ).toEqual(termsBefore)
  await document.unchanged()
  await page.getByTestId('nav-brand').click()
  await expect(page.getByTestId('self-user')).toBeVisible()
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)
  await document.dispose()
})

test('a stale revision is refused without optimistic coverage and can be retried', async ({
  page,
}) => {
  const requests: unknown[] = []
  let release: (() => void) | undefined
  let stale = true
  await page.routeWebSocket(
    (url) => url.pathname === '/ws',
    (socket) => {
      const server = socket.connectToServer()
      socket.onMessage((message) => {
        if (typeof message !== 'string') {
          server.send(message)
          return
        }
        const frame = JSON.parse(message)
        if (frame.type !== 'action' || frame.action !== 'hilos_legal_accept') {
          server.send(message)
          return
        }
        requests.push(structuredClone(frame.data))
        // Delay one real request to expose busy state, then exercise the real
        // server's current-revision gate with a published, older Privacy revision.
        if (stale) {
          release = () => {
            frame.data.acceptedRevisions.privacy = HELD_PRIVACY
            server.send(JSON.stringify(frame))
          }
        } else server.send(message)
      })
    },
  )
  const person = await signUp(expect, page)
  await gotoPage(expect, page, '/privacy', PAGE_READY)
  const reader = page.getByTestId('privacy-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await holdLegalRevision(person.userId, 'privacy', HELD_PRIVACY)
  await expect(reader).toHaveAttribute('data-state', 'due')
  await clickSubmit(expect, reader.getByTestId('legal-document-changes-open'))
  const comparison = page.getByTestId('privacy-changes-modal')
  await expect(comparison).toBeVisible()
  const refusalSlot = comparison.getByTestId('privacy-changes-refusal-slot')
  await expect(refusalSlot).toHaveAttribute('role', 'alert')
  await expect(refusalSlot).toHaveAttribute('aria-live', 'assertive')
  await expect(
    comparison.getByTestId('privacy-changes-refusal-idle'),
  ).toHaveAttribute('aria-hidden', 'true')

  const accept = page.getByTestId('privacy-changes-accept')
  await clickSubmit(expect, accept)
  await expect.poll(() => requests.length).toBe(1)
  expect(requests).toEqual([
    { acceptedRevisions: { privacy: CURRENT_PRIVACY } },
  ])
  await expect(accept).toHaveAttribute('aria-busy', 'true')
  await expect(reader.getByTestId('legal-document-accept')).toHaveAttribute(
    'aria-busy',
    'true',
  )
  await expect(page.getByTestId('privacy-changes-close')).toBeDisabled()
  await page.keyboard.press('Enter')
  await page.keyboard.press('Escape')
  await expect(comparison).toBeVisible()
  await expect(reader).toHaveAttribute('data-state', 'due')
  expect(requests).toHaveLength(1)
  expect(release).toBeDefined()
  release!()

  await expect(comparison.getByTestId('privacy-changes-refusal')).toHaveText(
    CONSENT_REVISED,
  )
  await expect(accept).toBeEnabled()
  await expect(reader).toHaveAttribute('data-state', 'due')
  await expect(
    comparison
      .getByTestId('legal-changes-wide')
      .getByTestId('legal-change-row'),
  ).toHaveCount(1)
  await expect(comparison).toBeVisible()
  // Closing retains the refusal beside the direct accept. Only the next press
  // clears it; neither the refusal nor the refresh turns the line green.
  await clickSubmit(expect, page.getByTestId('privacy-changes-close'))
  await expect(comparison).toHaveCount(0)
  await expect(reader.getByTestId('legal-document-accept-refusal')).toHaveText(
    CONSENT_REVISED,
  )
  stale = false
  await clickSubmit(expect, reader.getByTestId('legal-document-accept'))
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await expect(reader.getByTestId('legal-document-accept-refusal')).toHaveCount(
    0,
  )
  expect(requests).toEqual([
    { acceptedRevisions: { privacy: CURRENT_PRIVACY } },
    { acceptedRevisions: { privacy: CURRENT_PRIVACY } },
  ])
})
