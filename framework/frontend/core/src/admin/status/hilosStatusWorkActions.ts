import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosRowEdit,
} from '../../conflict/rowEditSession.js'
import {
  ActionError,
  type ActionHandle,
} from '../../connection/actionLifecycle.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { hilosAdminAccess } from '../../session/adminAccess.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import {
  localDateTimeValue,
  parseLocalDateTimeValue,
} from '../../timeZone/localDateTime.js'
import { hilosTimeZone } from '../../timeZone/timeZoneState.js'
import { HILOS_VIEW_MODE_COPY } from '../viewMode.js'
import {
  HILOS_STATUS_WORK_SLOT,
  type HilosStatusWorkRow,
  type HilosStatusWorksContext,
  type HilosStatusWorksState,
  type HilosStatusWorksTable,
} from './hilosStatusWorks.js'

/** Page-owned mutations; requestId stays in the action envelope. */
export const HILOS_STATUS_WORK_SCHEDULE_ACTION = 'hilos_status_work_schedule'
export const HILOS_STATUS_WORK_CHANGE_ACTION = 'hilos_status_work_change'
export const HILOS_STATUS_WORK_CANCEL_ACTION = 'hilos_status_work_cancel'

export const HILOS_STATUS_WORK_ACTION_COPY = {
  unavailable: 'Scheduled maintenance data is unavailable.',
  gone: 'This announcement is no longer available to change. Your draft stays to copy.',
  conflict:
    'Changed elsewhere. Choose Keep mine or Take theirs for the conflicting fields.',
  disconnected: 'A live connection is required to continue.',
  unconfirmed:
    'The result is unconfirmed. The operation may have completed. Check before submitting again.',
  localFailure: 'The action could not be completed. Please try again.',
  scheduled: 'Maintenance scheduled.',
  changed: 'Announcement changed.',
  canceled: 'Announcement canceled.',
} as const

/** Text inputs stay local; only parsed epoch milliseconds leave the form. */
export interface HilosStatusWorkForm {
  readonly title: string
  readonly description: string
  readonly componentKeys: readonly string[]
  readonly startsAt: string
  readonly endsAt: string
}

/** Scalar projection for the standard Object.is row merge. Lists compare by content. */
export interface HilosStatusWorkEditFields {
  readonly title: string
  readonly description: string | null
  readonly componentKeys: string
  readonly startsAt: number | null
  readonly endsAt: number | null
}

/** Tracked outcomes for the modal's reserved refusal slot and deferred button spinner. */
export interface HilosStatusWorkRequest {
  readonly busy: ReadonlySignal<boolean>
  readonly loading: ReadonlySignal<boolean>
  readonly error: ReadonlySignal<string | null>
  readonly failure: ReadonlySignal<ActionError | null>
  readonly unconfirmed: ReadonlySignal<boolean>
  readonly success: ReadonlySignal<string | null>
  readonly requestId: ReadonlySignal<string | null>
  readonly blockedReason: ReadonlySignal<string | null>
}

/** Shared local request lifetime, using the existing action lifecycle for correlation. */
function createWorkRequest(context: HilosStatusWorksContext) {
  const active = createSignal(false)
  const usable = createSignal(false)
  const busy = createSignal(false)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  const failure = createSignal<ActionError | null>(null)
  const unconfirmed = createSignal(false)
  const success = createSignal<string | null>(null)
  const requestId = createSignal<string | null>(null)
  const blockedReason = computedSignal(() => {
    if (hilosAdminAccess.get() !== 'full')
      return HILOS_VIEW_MODE_COPY.explanation
    if (!usable.get()) return HILOS_STATUS_WORK_ACTION_COPY.disconnected
    return null
  })
  let round = 0
  let disposed = false
  let offLoading: (() => void) | undefined
  const teardown: Array<() => void> = []
  const reset = (): void => {
    round++
    offLoading?.()
    offLoading = undefined
    busy.set(false)
    loading.set(false)
    error.set(null)
    failure.set(null)
    unconfirmed.set(false)
    success.set(null)
    requestId.set(null)
  }
  return {
    active,
    busy,
    loading,
    error,
    failure,
    unconfirmed,
    success,
    requestId,
    blockedReason,
    reset,
    start(dispose: () => void) {
      if (disposed || active.get()) return
      const scope = context.scopes.page()
      if (scope?.key !== HilosPages.STATUS_WORKS) return
      const update = (): void => {
        usable.set(
          context.connection.state === 'connected' &&
            !context.connection.protectedMode.active,
        )
      }
      teardown.push(
        subscribeSignal({ get: () => context.scopes.page() }, (current) => {
          if (current !== scope) dispose()
        }),
        context.connection.on('state', update),
        context.connection.on('protectedMode', update),
      )
      active.set(true)
      update()
    },
    dispose() {
      if (disposed) return
      disposed = true
      active.set(false)
      for (const off of teardown.splice(0)) off()
      reset()
    },
    async run(
      send: () => ActionHandle,
      current: () => boolean,
      message: string,
    ): Promise<boolean> {
      if (!active.get() || busy.get() || blockedReason.get() !== null)
        return false
      const attempt = ++round
      const scope = context.scopes.page()
      const relevant = (): boolean =>
        !disposed &&
        attempt === round &&
        scope === context.scopes.page() &&
        current()
      busy.set(true)
      error.set(null)
      failure.set(null)
      unconfirmed.set(false)
      success.set(null)
      try {
        const handle = send()
        requestId.set(handle.requestId)
        loading.set(handle.loading.get())
        offLoading = subscribeSignal(handle.loading, (value) => {
          if (relevant()) loading.set(value)
        })
        const result = await handle.done
        if (!relevant()) return false
        success.set(result.message ?? message)
        return true
      } catch (caught) {
        if (!relevant()) return false
        const actionFailure = caught instanceof ActionError ? caught : null
        const uncertain =
          actionFailure !== null && actionFailure.outcome !== 'fail'
        failure.set(actionFailure)
        unconfirmed.set(uncertain)
        error.set(
          actionFailure?.outcome === 'fail'
            ? actionFailure.message
            : uncertain
              ? HILOS_STATUS_WORK_ACTION_COPY.unconfirmed
              : HILOS_STATUS_WORK_ACTION_COPY.localFailure,
        )
        return false
      } finally {
        if (!disposed && attempt === round) {
          offLoading?.()
          offLoading = undefined
          loading.set(false)
          busy.set(false)
        }
      }
    },
  }
}

function emptyForm(): HilosStatusWorkForm {
  return {
    title: '',
    description: '',
    componentKeys: [],
    startsAt: '',
    endsAt: '',
  }
}

/** Project a component set onto one stable scalar, without sharing mutable arrays. */
function componentSet(keys: readonly string[]): string {
  return JSON.stringify([...keys].sort())
}

/** Parse editor values only through the shared explicit-zone calendar engine. */
function formFields(
  form: HilosStatusWorkForm,
  zone: string,
): HilosStatusWorkEditFields {
  return {
    title: form.title.trim(),
    description: form.description.trim() || null,
    componentKeys: componentSet(form.componentKeys),
    startsAt: parseLocalDateTimeValue(form.startsAt, zone),
    endsAt: parseLocalDateTimeValue(form.endsAt, zone),
  }
}

function validForm(
  form: HilosStatusWorkForm,
  zone: string,
  state: HilosStatusWorksState,
): boolean {
  const fields = formFields(form, zone)
  const catalog = state.data.get()?.components
  return (
    catalog !== undefined &&
    fields.title !== '' &&
    [...fields.title].length <= 200 &&
    [...(fields.description ?? '')].length <= 2000 &&
    form.componentKeys.length > 0 &&
    new Set(form.componentKeys).size === form.componentKeys.length &&
    form.componentKeys.every((key) =>
      catalog.some((component) => component.componentKey === key),
    ) &&
    fields.startsAt !== null &&
    fields.endsAt !== null &&
    fields.endsAt > fields.startsAt
  )
}

function actionData(fields: HilosStatusWorkEditFields) {
  return {
    title: fields.title,
    description: fields.description,
    componentKeys: JSON.parse(fields.componentKeys) as string[],
    startsAt: fields.startsAt!,
    endsAt: fields.endsAt!,
  }
}

/** A real new entity: no invented identity, live baseline or optimistic insertion. */
export interface HilosStatusWorkCreate extends HilosStatusWorkRequest {
  readonly opened: ReadonlySignal<boolean>
  /** Captured on open; a live reader-zone change never reinterprets typed text. */
  readonly timeZone: ReadonlySignal<string>
  readonly form: ReadonlySignal<HilosStatusWorkForm>
  readonly dirty: ReadonlySignal<boolean>
  readonly submitAvailable: ReadonlySignal<boolean>
  readonly canSubmit: ReadonlySignal<boolean>
  start(): void
  open(): boolean
  patchForm(patch: Partial<HilosStatusWorkForm>): void
  /** Called by the modal only after its standard dirty-close confirmation. */
  close(): void
  submit(): Promise<boolean>
  dispose(): void
}

/**
 * Create the Schedule dialog using only the catalog in the page's first answer.
 *
 * @param context The page's scopes, connection and tracked actions.
 * @param state The catalog and independent summary from page_response.
 */
export function createHilosStatusWorkCreate(
  context: HilosStatusWorksContext,
  state: HilosStatusWorksState,
): HilosStatusWorkCreate {
  const request = createWorkRequest(context)
  const opened = createSignal(false)
  const form = createSignal(emptyForm())
  const timeZone = createSignal('UTC')
  let generation = 0
  const blockedReason = computedSignal(
    () =>
      request.blockedReason.get() ??
      (state.data.get() === null
        ? HILOS_STATUS_WORK_ACTION_COPY.unavailable
        : null),
  )
  const submitAvailable = computedSignal(
    () =>
      request.active.get() &&
      opened.get() &&
      blockedReason.get() === null &&
      validForm(form.get(), timeZone.get(), state),
  )
  const canSubmit = computedSignal(
    () => submitAvailable.get() && !request.busy.get(),
  )
  const close = (): void => {
    generation++
    opened.set(false)
  }
  const dispose = (): void => {
    request.dispose()
    close()
  }
  return {
    ...request,
    opened,
    form,
    timeZone,
    blockedReason,
    submitAvailable,
    canSubmit,
    dirty: computedSignal(() => {
      const value = form.get()
      return (
        value.title !== '' ||
        value.description !== '' ||
        value.componentKeys.length > 0 ||
        value.startsAt !== '' ||
        value.endsAt !== ''
      )
    }),
    start: () => request.start(dispose),
    open() {
      if (!request.active.get() || request.busy.get()) return false
      request.reset()
      generation++
      timeZone.set(hilosTimeZone.get())
      form.set(emptyForm())
      opened.set(true)
      return true
    },
    patchForm(patch) {
      if (opened.get() && !request.busy.get())
        form.set({ ...form.get(), ...patch })
    },
    close() {
      if (!request.busy.get()) close()
    },
    async submit() {
      if (!canSubmit.get()) return false
      const attempt = generation
      const saved = await request.run(
        () =>
          context.actions.dispatch(
            HILOS_STATUS_WORK_SCHEDULE_ACTION,
            actionData(formFields(form.get(), timeZone.get())),
          ),
        () => opened.get() && attempt === generation,
        HILOS_STATUS_WORK_ACTION_COPY.scheduled,
      )
      if (saved) close()
      return saved
    },
    dispose,
  }
}

/** Local identity of a focused announcement; domain metadata is never merged. */
export interface HilosStatusWorkTarget {
  readonly workId: number
  readonly publicId: string
  readonly focusGeneration: number
}

/** One focus source shared by the edit and cancel factories, not a second row store. */
function workSource(table: HilosStatusWorksTable) {
  const parent = hilosTableRowEditSource(table.controller, (row) =>
    String(row.id),
  )
  const identity = createSignal<HilosStatusWorkTarget | null>(null)
  const current = (): boolean => {
    const held = identity.get()
    return (
      held !== null &&
      table.focusKey.get() === String(held.workId) &&
      table.focusGeneration.get() === held.focusGeneration
    )
  }
  const target = computedSignal(() => {
    const live = parent.live.get()
    const held = identity.get()
    return current() &&
      live?.id === held?.workId &&
      live?.publicId === held?.publicId
      ? live
      : undefined
  })
  return {
    identity,
    target,
    current,
    open(key?: string) {
      const row = parent.open(key)
      if (row === null) return null
      table.claimFocus()
      identity.set({
        workId: row.id,
        publicId: row.publicId,
        focusGeneration: table.focusGeneration.get(),
      })
      return row
    },
    live: computedSignal(() => {
      const row = target.get()
      return row !== undefined &&
        row.canceledAt === null &&
        row.consumedAt === null &&
        row.expiredAt === null
        ? row
        : undefined
    }),
    close() {
      // A stale dialog must never release the focus another dialog now holds.
      if (current()) parent.close()
      identity.set(null)
    },
  }
}

function targetBlocked(
  table: HilosStatusWorksTable,
  target: HilosStatusWorkRow | undefined,
): string | null {
  if (
    table.controller.frozenSince.get() !== null ||
    table.controller.frame.body.get() === 'unavailable' ||
    table.controller.rows
      .get()
      .some(
        (row) =>
          row.rowKey === String(target?.id) &&
          row.staleSources.includes(HILOS_STATUS_WORK_SLOT),
      )
  )
    return HILOS_STATUS_WORK_ACTION_COPY.unavailable
  if (
    target === undefined ||
    target.canceledAt !== null ||
    target.consumedAt !== null ||
    target.expiredAt !== null
  )
    return HILOS_STATUS_WORK_ACTION_COPY.gone
  return null
}

export interface HilosStatusWorkEdit
  extends
    Omit<
      HilosRowEdit<
        HilosStatusWorkRow,
        HilosStatusWorkEditFields,
        HilosStatusWorkForm
      >,
      'open' | 'close' | 'save' | 'start' | 'dispose'
    >,
    HilosStatusWorkRequest {
  readonly timeZone: ReadonlySignal<string>
  readonly target: ReadonlySignal<HilosStatusWorkRow | undefined>
  readonly dirty: ReadonlySignal<boolean>
  start(): void
  open(rowKey: string): boolean
  close(): void
  /** The standard session also closes an unchanged edit without sending. */
  submit(): Promise<boolean>
  dispose(): void
}

/**
 * Edit all five fields through the standard row session, reading the live version on send.
 *
 * @param context The page's scopes, connection and tracked actions.
 * @param table The standard Works viewport and focus transport.
 * @param state The catalog from the page answer.
 */
export function createHilosStatusWorkEdit(
  context: HilosStatusWorksContext,
  table: HilosStatusWorksTable,
  state: HilosStatusWorksState,
): HilosStatusWorkEdit {
  const request = createWorkRequest(context)
  const source = workSource(table)
  const timeZone = createSignal('UTC')
  const blockedReason = computedSignal(
    () =>
      request.blockedReason.get() ??
      (state.data.get() === null
        ? HILOS_STATUS_WORK_ACTION_COPY.unavailable
        : targetBlocked(table, source.target.get())),
  )
  const edit = createHilosRowEdit<
    HilosStatusWorkRow,
    HilosStatusWorkEditFields,
    HilosStatusWorkForm
  >(source, {
    initial: emptyForm(),
    fields: (row) => ({
      title: row.title,
      description: row.description,
      componentKeys: componentSet(row.componentKeys),
      startsAt: row.startsAt,
      endsAt: row.endsAt,
    }),
    form: (row) => ({
      title: row.title,
      description: row.description ?? '',
      componentKeys: [...row.componentKeys],
      startsAt: localDateTimeValue(row.startsAt, timeZone.get()),
      endsAt: localDateTimeValue(row.endsAt, timeZone.get()),
    }),
    draft: (form, row) => {
      const fields = formFields(form, timeZone.get())
      // The input shows seconds. Preserve a saved subsecond instant when the
      // person has left that shown second unchanged, including after a live take.
      const moment = (
        parsed: number | null,
        live: number | undefined,
        initial: number,
      ): number | null =>
        [live, initial].find(
          (value) =>
            value !== undefined &&
            parsed !== null &&
            Math.floor(value / 1000) * 1000 === parsed,
        ) ?? parsed
      const live = source.target.get()
      return {
        ...fields,
        startsAt: moment(fields.startsAt, live?.startsAt, row.startsAt),
        endsAt: moment(fields.endsAt, live?.endsAt, row.endsAt),
      }
    },
    take: (form, taken) => ({
      title: taken.title ?? form.title,
      description:
        taken.description === undefined
          ? form.description
          : (taken.description ?? ''),
      componentKeys:
        taken.componentKeys === undefined
          ? form.componentKeys
          : (JSON.parse(taken.componentKeys) as string[]),
      startsAt:
        taken.startsAt === undefined || taken.startsAt === null
          ? form.startsAt
          : localDateTimeValue(taken.startsAt, timeZone.get()),
      endsAt:
        taken.endsAt === undefined || taken.endsAt === null
          ? form.endsAt
          : localDateTimeValue(taken.endsAt, timeZone.get()),
    }),
    valid: (form) =>
      request.active.get() &&
      blockedReason.get() === null &&
      validForm(form, timeZone.get(), state),
    notice: {
      deleted: HILOS_STATUS_WORK_ACTION_COPY.gone,
      conflict: () => HILOS_STATUS_WORK_ACTION_COPY.conflict,
    },
  })
  const dispose = (): void => {
    request.dispose()
    edit.dispose()
  }
  return {
    ...edit,
    ...request,
    timeZone,
    target: source.target,
    blockedReason,
    dirty: computedSignal(() => edit.state.get().dirty),
    start() {
      request.start(dispose)
      if (request.active.get()) edit.start()
    },
    open(rowKey) {
      if (!request.active.get() || request.busy.get() || edit.saving.get())
        return false
      edit.close()
      request.reset()
      timeZone.set(hilosTimeZone.get())
      return edit.open(rowKey)
    },
    setForm(form) {
      if (!edit.saving.get()) edit.setForm(form)
    },
    patchForm(patch) {
      if (!edit.saving.get()) edit.patchForm(patch)
    },
    keepMine() {
      if (!edit.saving.get()) edit.keepMine()
    },
    takeTheirs() {
      if (!edit.saving.get()) edit.takeTheirs()
    },
    close() {
      if (!edit.saving.get()) edit.close()
    },
    async submit() {
      if (
        !request.active.get() ||
        !edit.opened.get() ||
        blockedReason.get() !== null ||
        edit.saving.get()
      )
        return false
      let saved = false
      await edit.save((draft) =>
        request
          .run(
            () => {
              const live = source.live.get()!
              return context.actions.dispatch(HILOS_STATUS_WORK_CHANGE_ACTION, {
                workId: live.id,
                publicId: live.publicId,
                expectedVersion: live.version,
                ...actionData(draft),
              })
            },
            () => source.current(),
            HILOS_STATUS_WORK_ACTION_COPY.changed,
          )
          .then((result) => {
            saved = result
            return result
          }),
      )
      return saved || (request.active.get() && !edit.opened.get())
    },
    dispose,
  }
}

export interface HilosStatusWorkCancel extends HilosStatusWorkRequest {
  readonly opened: ReadonlySignal<boolean>
  /** Live caption and metadata of the same immutable public identity. */
  readonly target: ReadonlySignal<HilosStatusWorkRow | undefined>
  readonly canSubmit: ReadonlySignal<boolean>
  start(): void
  open(rowKey: string): boolean
  close(): void
  submit(): Promise<boolean>
  dispose(): void
}

/**
 * Cancel the focused announcement by its immutable identity and current live version.
 *
 * @param context The page's scopes, connection and tracked actions.
 * @param table The standard Works viewport and focus transport.
 */
export function createHilosStatusWorkCancel(
  context: HilosStatusWorksContext,
  table: HilosStatusWorksTable,
): HilosStatusWorkCancel {
  const request = createWorkRequest(context)
  const source = workSource(table)
  const opened = createSignal(false)
  const blockedReason = computedSignal(
    () =>
      request.blockedReason.get() ?? targetBlocked(table, source.target.get()),
  )
  const canSubmit = computedSignal(
    () =>
      request.active.get() &&
      opened.get() &&
      !request.busy.get() &&
      blockedReason.get() === null,
  )
  const close = (): void => {
    source.close()
    opened.set(false)
  }
  const dispose = (): void => {
    request.dispose()
    close()
  }
  return {
    ...request,
    opened,
    target: source.target,
    blockedReason,
    canSubmit,
    start: () => request.start(dispose),
    open(rowKey) {
      if (!request.active.get() || request.busy.get()) return false
      close()
      request.reset()
      if (source.open(rowKey) === null) return false
      opened.set(true)
      return true
    },
    close() {
      if (!request.busy.get()) close()
    },
    async submit() {
      if (!canSubmit.get()) return false
      const live = source.live.get()!
      const identity = source.identity.get()
      const saved = await request.run(
        () =>
          context.actions.dispatch(HILOS_STATUS_WORK_CANCEL_ACTION, {
            workId: live.id,
            publicId: live.publicId,
            expectedVersion: live.version,
          }),
        () =>
          opened.get() &&
          source.identity.get() === identity &&
          source.current(),
        HILOS_STATUS_WORK_ACTION_COPY.canceled,
      )
      if (saved) close()
      return saved
    },
    dispose,
  }
}
