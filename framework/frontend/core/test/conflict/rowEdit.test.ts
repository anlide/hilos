import { describe, expect, it } from 'vitest'

import {
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type RowEditBaseline,
} from '../../src/conflict/rowEdit.js'

/** The one-field shape the three framework windows edit. */
interface Override {
  overrideValue: string | null
}

/** A two-field shape: the case a project modal with several inputs is. */
interface Pair {
  name: string
  note: string
}

describe('openRowEdit', () => {
  it('freezes the saved values with nothing refreshed', () => {
    expect(openRowEdit({ overrideValue: 'en' })).toEqual({
      values: { overrideValue: 'en' },
      refreshed: [],
    })
  })
})

describe('resolveRowEdit', () => {
  const opened = openRowEdit<Override>({ overrideValue: 'en' })
  const field = (
    live: string | null,
    draft: string | null,
    baseline: RowEditBaseline<Override> = opened,
  ) =>
    resolveRowEdit({ overrideValue: live }, baseline, {
      overrideValue: draft,
    })

  it('classifies unchanged when nobody moved the field', () => {
    const state = field('en', 'en')
    expect(state.fields.overrideValue).toEqual({
      status: 'unchanged',
      conflict: false,
      value: 'en',
      incoming: 'en',
      refreshed: false,
    })
    expect(state).toMatchObject({
      gone: false,
      conflict: false,
      dirty: false,
      settle: null,
      notice: null,
    })
  })

  it('classifies user when only the draft moved, and Save has it to send', () => {
    const state = field('en', 'de')
    expect(state.fields.overrideValue.status).toBe('user')
    expect(state.fields.overrideValue.value).toBe('de')
    expect(state).toMatchObject({
      conflict: false,
      dirty: true,
      settle: null,
      notice: null,
    })
  })

  it('classifies incoming when only the live row moved, with a step that takes it', () => {
    const state = field('de', 'en')
    expect(state.fields.overrideValue).toMatchObject({
      status: 'incoming',
      incoming: 'de',
      value: 'de',
    })
    // The value goes into the form and the snapshot, marked as taken.
    expect(state.settle).toEqual({
      baseline: {
        values: { overrideValue: 'de' },
        refreshed: ['overrideValue'],
      },
      take: { overrideValue: 'de' },
    })
    expect(state.dirty).toBe(true)
    // Not yet: the message follows the step, once the form shows the value.
    expect(state.notice).toBeNull()
  })

  it('classifies converged when both reached the same value, moving only the snapshot', () => {
    const state = field('de', 'de')
    expect(state.fields.overrideValue.status).toBe('converged')
    expect(state.settle).toEqual({
      baseline: { values: { overrideValue: 'de' }, refreshed: [] },
      take: {},
    })
    expect(state).toMatchObject({ conflict: false, dirty: false, notice: null })
  })

  it('classifies conflict when both moved apart, keeping the draft and naming the field', () => {
    const state = field('de', 'fr')
    expect(state.fields.overrideValue).toMatchObject({
      status: 'conflict',
      conflict: true,
      value: 'fr',
      incoming: 'de',
    })
    expect(state).toMatchObject({
      conflict: true,
      dirty: true,
      settle: null,
      notice: { kind: 'conflict', fields: ['overrideValue'] },
    })
  })

  it('says updated while the form shows a taken value the person left alone', () => {
    const taken: RowEditBaseline<Override> = {
      values: { overrideValue: 'de' },
      refreshed: ['overrideValue'],
    }
    const state = field('de', 'de', taken)
    expect(state.fields.overrideValue.refreshed).toBe(true)
    expect(state.notice).toEqual({
      kind: 'updated',
      fields: ['overrideValue'],
    })
    expect(state).toMatchObject({ dirty: false, settle: null })
  })

  it('drops updated the moment the person types over the taken value', () => {
    const taken: RowEditBaseline<Override> = {
      values: { overrideValue: 'de' },
      refreshed: ['overrideValue'],
    }
    const state = field('de', 'mine', taken)
    expect(state.fields.overrideValue).toMatchObject({
      status: 'user',
      refreshed: false,
    })
    expect(state.notice).toBeNull()
  })

  it('reads a gone row as deleted: nothing to send, the draft kept to copy', () => {
    const state = resolveRowEdit(undefined, opened, { overrideValue: 'mine' })
    expect(state.fields.overrideValue).toEqual({
      status: 'unchanged',
      conflict: false,
      value: 'mine',
      incoming: 'en',
      refreshed: false,
    })
    expect(state).toMatchObject({
      gone: true,
      conflict: false,
      dirty: false,
      settle: null,
      notice: { kind: 'deleted', fields: [] },
    })
  })

  it('ranks deleted over conflict over updated', () => {
    const taken: RowEditBaseline<Override> = {
      values: { overrideValue: 'de' },
      refreshed: ['overrideValue'],
    }
    // The taken value is on screen, and the other side moved again while the
    // person typed: the conflict is what is said, not the earlier take.
    expect(field('fr', 'mine', taken).notice?.kind).toBe('conflict')
    // And a row gone under any of that is what is said first.
    expect(
      resolveRowEdit(undefined, taken, { overrideValue: 'mine' }).notice?.kind,
    ).toBe('deleted')
  })

  it('merges a window of two fields one at a time', () => {
    const baseline = openRowEdit<Pair>({ name: 'a', note: 'x' })
    const state = resolveRowEdit({ name: 'b', note: 'y' }, baseline, {
      name: 'a',
      note: 'z',
    })
    // The name only the other side touched is taken; the note both touched
    // apart stands as the conflict.
    expect(state.fields.name.status).toBe('incoming')
    expect(state.fields.note.status).toBe('conflict')
    expect(state.conflict).toBe(true)
    expect(state.settle).toEqual({
      baseline: { values: { name: 'b', note: 'x' }, refreshed: ['name'] },
      take: { name: 'b' },
    })
    expect(state.notice).toEqual({ kind: 'conflict', fields: ['note'] })
  })
})

describe('keepMineRowEdit', () => {
  it('moves the snapshot of the conflicting field to the live value and keeps the draft', () => {
    const baseline = openRowEdit({ overrideValue: 'en' })
    const state = resolveRowEdit({ overrideValue: 'de' }, baseline, {
      overrideValue: 'fr',
    })

    const kept = keepMineRowEdit(state, baseline)
    expect(kept).toEqual({ values: { overrideValue: 'de' }, refreshed: [] })
    // The conflict is over, and the draft is what Save sends.
    const after = resolveRowEdit({ overrideValue: 'de' }, kept, {
      overrideValue: 'fr',
    })
    expect(after).toMatchObject({
      conflict: false,
      dirty: true,
      notice: null,
    })
    expect(after.fields.overrideValue.status).toBe('user')
  })

  it('leaves a field that does not conflict alone', () => {
    const baseline: RowEditBaseline<Pair> = {
      values: { name: 'a', note: 'x' },
      refreshed: ['name'],
    }
    const state = resolveRowEdit({ name: 'a', note: 'y' }, baseline, {
      name: 'a',
      note: 'z',
    })

    expect(keepMineRowEdit(state, baseline)).toEqual({
      values: { name: 'a', note: 'y' },
      refreshed: ['name'],
    })
  })
})

describe('takeTheirsRowEdit', () => {
  it('takes the live value into the form and the snapshot, marked refreshed', () => {
    const baseline = openRowEdit({ overrideValue: 'en' })
    const state = resolveRowEdit({ overrideValue: 'de' }, baseline, {
      overrideValue: 'fr',
    })

    const step = takeTheirsRowEdit(state, baseline)
    expect(step).toEqual({
      baseline: {
        values: { overrideValue: 'de' },
        refreshed: ['overrideValue'],
      },
      take: { overrideValue: 'de' },
    })
    // Once the form shows it, the modal says so and Save has nothing to send.
    const after = resolveRowEdit({ overrideValue: 'de' }, step.baseline, {
      overrideValue: 'de',
    })
    expect(after).toMatchObject({
      conflict: false,
      dirty: false,
      notice: { kind: 'updated', fields: ['overrideValue'] },
    })
  })
})
