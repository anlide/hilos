import { describe, expect, it } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
  type ActionResult,
} from '../../src/connection/actionLifecycle.js'
import {
  createHilosLegalRevisionReader,
  describeHilosLegalRevision,
} from '../../src/legal/legalRevisions.js'
import { clause, current, first, legalContext } from './fixtures.js'

function readerWorld() {
  const requests: {
    name: string
    payload: unknown
    resolve(value: ActionResult): void
    reject(error: Error): void
  }[] = []
  const actions = {
    dispatch(name: string, payload: unknown) {
      return {
        done: new Promise<ActionResult>((resolve, reject) =>
          requests.push({ name, payload, resolve, reject }),
        ),
      }
    },
  } as unknown as ActionLifecycle
  return {
    reader: createHilosLegalRevisionReader(legalContext(actions).context),
    requests,
  }
}

describe('legal revision reader', () => {
  it('opens immediately and sends the exact document and revision', async () => {
    const { reader, requests } = readerWorld()
    const done = reader.open('terms', first.revisionId)
    expect(reader.dialog.get()?.busy).toBe(true)
    expect(requests[0]?.name).toBe('hilos_legal_revision_text')
    expect(requests[0]?.payload).toEqual({
      document: 'terms',
      revisionId: first.revisionId,
    })
    requests[0]!.resolve({
      reply: {
        document: 'terms',
        revisionId: first.revisionId,
        clauses: [clause],
      },
    })
    await done
    expect(reader.dialog.get()?.text?.clauses).toEqual([clause])
    expect(reader.dialog.get()?.busy).toBe(false)
  })
  it('keeps a refusal in the open dialog', async () => {
    const { reader, requests } = readerWorld()
    const done = reader.compare('privacy', first.revisionId)
    expect(requests[0]?.name).toBe('hilos_legal_revision_changes')
    requests[0]!.reject(
      new ActionError(
        'read',
        'fail',
        'The first revision has nothing to compare with',
      ),
    )
    await done
    expect(reader.dialog.get()).toMatchObject({
      busy: false,
      refusal: 'The first revision has nothing to compare with',
    })
  })
  it('ignores late success and failure after replacement or close', async () => {
    const { reader, requests } = readerWorld()
    const firstRead = reader.open('terms', first.revisionId)
    const secondRead = reader.compare('terms', current.revisionId)
    requests[0]!.resolve({
      reply: {
        document: 'terms',
        revisionId: first.revisionId,
        clauses: [clause],
      },
    })
    await firstRead
    expect(reader.dialog.get()).toMatchObject({ kind: 'changes', busy: true })
    reader.close()
    requests[1]!.reject(new ActionError('read', 'timeout', 'Timed out'))
    await secondRead
    expect(reader.dialog.get()).toBeNull()
  })
  it('does not keep spinning if a reply has no content', async () => {
    const { reader, requests } = readerWorld()
    const done = reader.open('terms', first.revisionId)
    requests[0]!.resolve({})
    await done
    expect(reader.dialog.get()?.busy).toBe(false)
    expect(reader.dialog.get()?.refusal).toContain('no revision text')
  })
  it('describes first, project and standard provenance', () => {
    expect(
      describeHilosLegalRevision({
        ...first,
        origin: 'first',
        previousSetVersion: null,
      }),
    ).toBe('First revision · Hilos standard 1')
    expect(
      describeHilosLegalRevision({
        ...current,
        origin: 'project',
        previousSetVersion: 1,
      }),
    ).toBe('Editorial change · changed by the project')
    expect(
      describeHilosLegalRevision({
        ...current,
        setVersion: 2,
        origin: 'standard',
        previousSetVersion: 1,
      }),
    ).toContain('set 1 → 2')
  })
})
