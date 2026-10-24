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
import { hilosToasts } from '../../state/toasts.js'
import {
  type HilosStatusContext,
  type HilosStatusIncident,
  type HilosStatusState,
  type HilosStatusIncidentCard,
} from './hilosStatus.js'

export const HILOS_STATUS_CANCEL_PUBLICATION_ACTION =
  'hilos_status_cancel_publication'
export const HILOS_STATUS_PUBLISH_NOW_ACTION = 'hilos_status_publish_now'
export const HILOS_STATUS_ADD_UPDATE_ACTION = 'hilos_status_add_update'

export type HilosStatusIncidentOperation =
  'cancel-publication' | 'publish-now' | 'add-update'

/** Shared modal copy; no subscriber counts or claims of delivery. */
export const HILOS_STATUS_ACTION_COPY = {
  cancel: 'Cancel',
  updateField: 'Update',
  updatePlaceholder: 'Describe what the team knows about this outage.',
  unavailable:
    'Incident data is unavailable. Keep your draft until it returns.',
  ended: 'This incident has ended. Your draft is still available to copy.',
  suppressed: 'Publication was canceled for this incident.',
  published: 'This incident is already published.',
  unpublished: 'Updates are available after the incident is published.',
  // TODO(i18n): Local transport outcomes have no backend-authored sentence.
  unconfirmed:
    'The result is unconfirmed. The operation may have completed. Check the incident before submitting again.',
  localFailure: 'The action could not be completed. Please try again.',
  'cancel-publication': {
    title: 'Do not publish',
    confirm: 'Do not publish',
    lead: 'Checking remains until full recovery. Publication is canceled for this entire continuous incident. This cannot be undone.',
    success: 'Publication canceled.',
    refusalTitle: "Couldn't cancel publication",
  },
  'publish-now': {
    title: 'Publish the outage now',
    confirm: 'Publish now',
    lead: 'Publication cannot be undone. The incident closes automatically after full recovery.',
    success: 'Outage published.',
    refusalTitle: "Couldn't publish the outage",
  },
  'add-update': {
    title: 'Add an update',
    confirm: 'Publish update',
    lead: 'This plain-text update will be appended to the incident timeline. Published updates cannot be edited.',
    success: 'Update published.',
    refusalTitle: "Couldn't publish the update",
  },
} as const

/** Dispatchers preserve the captured target and send text as typed for backend validation. */
export interface HilosStatusActions {
  cancelPublication(incidentId: number): ActionHandle
  publishNow(incidentId: number): ActionHandle
  addUpdate(incidentId: number, text: string): ActionHandle
}

export function createHilosStatusActions(
  context: HilosStatusContext,
): HilosStatusActions {
  return {
    cancelPublication: (incidentId) =>
      context.actions.dispatch(HILOS_STATUS_CANCEL_PUBLICATION_ACTION, {
        incidentId,
      }),
    publishNow: (incidentId) =>
      context.actions.dispatch(HILOS_STATUS_PUBLISH_NOW_ACTION, { incidentId }),
    addUpdate: (incidentId, text) =>
      context.actions.dispatch(HILOS_STATUS_ADD_UPDATE_ACTION, {
        incidentId,
        text,
      }),
  }
}

/** The opening incident is a stable caption even if that incident disappears. */
export interface HilosStatusIncidentDialog {
  readonly operation: HilosStatusIncidentOperation
  readonly incident: HilosStatusIncident
}

/** One modal state machine for the three parameterized operations. */
export interface HilosStatusIncidentController {
  readonly dialog: ReadonlySignal<HilosStatusIncidentDialog | null>
  readonly target: ReadonlySignal<HilosStatusIncidentCard | null>
  readonly draft: ReadonlySignal<string>
  readonly busy: ReadonlySignal<boolean>
  readonly loading: ReadonlySignal<boolean>
  readonly error: ReadonlySignal<string | null>
  readonly failure: ReadonlySignal<ActionError | null>
  readonly unconfirmed: ReadonlySignal<boolean>
  readonly blockedReason: ReadonlySignal<string | null>
  readonly canSubmit: ReadonlySignal<boolean>
  readonly requestId: ReadonlySignal<string | null>
  start(): void
  open(operation: HilosStatusIncidentOperation, incidentId: number): void
  setDraft(text: string): void
  close(): void
  submit(): Promise<boolean>
  dispose(): void
}

/** Draft/busy/error state belongs to this mount, never to a DB or RT truth source. */
export function createHilosStatusIncidentController(
  context: HilosStatusContext,
  state: HilosStatusState,
): HilosStatusIncidentController {
  const actions = createHilosStatusActions(context)
  const dialog = createSignal<HilosStatusIncidentDialog | null>(null)
  const draft = createSignal('')
  const busy = createSignal(false)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  const failure = createSignal<ActionError | null>(null)
  const requestId = createSignal<string | null>(null)
  const unconfirmed = createSignal(false)
  let off: (() => void) | undefined
  let offLoading: (() => void) | undefined
  let round = 0
  let disposed = false
  let openedScope = context.scopes.page()
  const target = computedSignal(
    () =>
      state.cards
        .get()
        .find(
          (card) =>
            card.incident.incidentId === dialog.get()?.incident.incidentId,
        ) ?? null,
  )
  const blockedReason = computedSignal(() => {
    const current = dialog.get()
    if (current === null) return null
    if (state.overview.get()?.incidents == null)
      return HILOS_STATUS_ACTION_COPY.unavailable
    const incident = target.get()?.incident
    if (incident === undefined) return HILOS_STATUS_ACTION_COPY.ended
    if (incident.suppressedAt !== null)
      return HILOS_STATUS_ACTION_COPY.suppressed
    if (current.operation === 'add-update')
      return incident.publishedAt === null
        ? HILOS_STATUS_ACTION_COPY.unpublished
        : null
    return incident.publishedAt !== null
      ? HILOS_STATUS_ACTION_COPY.published
      : null
  })
  const canSubmit = computedSignal(
    () =>
      !disposed &&
      dialog.get() !== null &&
      openedScope === context.scopes.page() &&
      hilosAdminAccess.get() === 'full' &&
      !busy.get() &&
      blockedReason.get() === null &&
      (dialog.get()?.operation !== 'add-update' ||
        draft.get().trim().length > 0),
  )
  const reset = (): void => {
    round++
    offLoading?.()
    offLoading = undefined
    dialog.set(null)
    draft.set('')
    busy.set(false)
    loading.set(false)
    error.set(null)
    failure.set(null)
    requestId.set(null)
    unconfirmed.set(false)
  }
  return {
    dialog,
    target,
    draft,
    busy,
    loading,
    error,
    failure,
    requestId,
    unconfirmed,
    blockedReason,
    canSubmit,
    start() {
      if (disposed || off !== undefined) return
      off = subscribeSignal({ get: () => context.scopes.page() }, () => reset())
    },
    open(operation, incidentId) {
      if (
        disposed ||
        off === undefined ||
        busy.get() ||
        context.scopes.page()?.key !== HilosPages.STATUS
      )
        return
      const incident = state.overview
        .get()
        ?.incidents?.find((item) => item.incidentId === incidentId)
      if (incident === undefined) return
      reset()
      openedScope = context.scopes.page()
      dialog.set({ operation, incident })
    },
    setDraft(text) {
      if (!disposed && dialog.get() !== null && !busy.get()) draft.set(text)
    },
    close() {
      if (!busy.get()) reset()
    },
    async submit() {
      if (!canSubmit.get()) return false
      const current = dialog.get()!
      const started = ++round
      busy.set(true)
      error.set(null)
      failure.set(null)
      unconfirmed.set(false)
      const handle =
        current.operation === 'add-update'
          ? actions.addUpdate(current.incident.incidentId, draft.get())
          : current.operation === 'publish-now'
            ? actions.publishNow(current.incident.incidentId)
            : actions.cancelPublication(current.incident.incidentId)
      requestId.set(handle.requestId)
      loading.set(handle.loading.get())
      offLoading = subscribeSignal(handle.loading, (value) =>
        loading.set(value),
      )
      try {
        const result = await handle.done
        if (
          disposed ||
          started !== round ||
          openedScope !== context.scopes.page()
        )
          return false
        hilosToasts.push(
          result.message ?? HILOS_STATUS_ACTION_COPY[current.operation].success,
          { severity: 'success' },
        )
        reset()
        return true
      } catch (caught) {
        if (
          disposed ||
          started !== round ||
          openedScope !== context.scopes.page()
        )
          return false
        const actionFailure = caught instanceof ActionError ? caught : null
        failure.set(actionFailure)
        const uncertain =
          actionFailure?.outcome === 'timeout' ||
          actionFailure?.outcome === 'disconnected' ||
          actionFailure?.outcome === 'invalid-reply'
        unconfirmed.set(uncertain)
        const message =
          actionFailure?.outcome === 'fail'
            ? actionFailure.message
            : uncertain
              ? HILOS_STATUS_ACTION_COPY.unconfirmed
              : HILOS_STATUS_ACTION_COPY.localFailure
        error.set(message)
        hilosToasts.push(message, { severity: 'error' })
        return false
      } finally {
        if (started === round) {
          offLoading?.()
          offLoading = undefined
          loading.set(false)
          busy.set(false)
        }
      }
    },
    dispose() {
      disposed = true
      off?.()
      off = undefined
      reset()
    },
  }
}
