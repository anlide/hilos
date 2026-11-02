<!-- HilosUserSessionsPage — the framework Sessions tab of a person's card
(HilosPages.USER_SESSIONS, HIL-1662): the browsers the person signed in with,
the live tabs open in each, and the two ways an administrator ends them — one
session, or all of them. The list is the core's (createHilosUserSessions): it
arrives with the page's one answer and follows the server's own updates, so
this view fetches nothing, polls nothing and removes no row by itself; a row is
the profile's (HilosSessionRow), whose tabs open here by session id. A session
in which an administrator acts as the person is marked with that administrator's
name, which follows a rename without a reload.
Each ending stands in its own window, after the confirmation step the card's
windows open with (createHilosUserCardStepUp, operation `end_user_sessions`).
The single window holds the person and the session it was opened on: when that
session leaves the person's list — ended elsewhere, or bound to somebody else —
the window stays and says it has already ended, and its Confirm is off. The
window for all of them holds the person and reads its number from the live list,
and says the same of an empty list. Neither says so of its own ending until the
window has acted on the answer: the server's answer and its update of the list
reach the tab in either order, the answer usually first. A success closes the
window and the server's sentence comes as a toast; a refusal stays in the
window. An ending takes its row's End with it, and an emptied list turns End all
sessions off; in either order the focus lands on End all sessions, or on the tab
itself when that is off too. On the administrator's
own card the windows say this tab may lose the account too: ending reaches every
session of the person, theirs included, and losing the account is the session's
and the page gate's business, not this page's.
A merged account keeps the reading and loses both endings. A viewer of the admin
view mode is shown the list hidden as a whole and the window for all of them
without the confirmation step, its Confirm disabled by the mode. While the
connection serves a frozen copy of the runtime, a strip over the list says the
tabs may lag, one line long with the whole of it behind a button; the sessions
themselves are read from the database and stay live, and so do the endings.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  focusableElements,
  focusInitial,
  HIDDEN_VALUE,
  HILOS_STEP_UP_COPY,
  HilosPages,
  hiddenAsWord,
  isHiddenValue,
  registerFocusReturnFallback,
  sessionUserId,
  type Hideable,
  type HilosStepUpOpenOutcome,
} from '@hilos/core/base'
import {
  createHilosUserCardStepUp,
  createHilosUserDetail,
  createHilosUserSessions,
  createHilosUserStanding,
  hilosUserMergedNotice,
  type HilosUserSession,
  type HilosUsersContext,
} from '@hilos/core/admin/users'

import HilosStepUpStep from '../../auth/HilosStepUpStep.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLongText from '../../HilosLongText.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import HilosSessionRow from '../../profile/HilosSessionRow.vue'
import { useConnectionState } from '../../useConnectionState.js'
import { useRtStaleness } from '../../useRtStaleness.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import HilosUserPageShell from './HilosUserPageShell.vue'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

/** The words the single window keeps while its session is gone from the list. */
const SESSION_GONE = 'This session has already ended'
/** The words the window for all of them keeps while the list is empty. */
const ALL_GONE = 'All sessions have already ended'
/** The strip over the list while a frozen copy may hold the tabs back. */
const TABS_MAY_LAG =
  'The tab lists may lag behind right now; the sessions themselves are current.'

const detail = useSignal(createHilosUserDetail(props.context))
const controller = createHilosUserSessions(props.context)
const sessions = useSignal(controller.sessions)
const userStanding = createHilosUserStanding(props.context)
const standing = useSignal(userStanding.standing)
onMounted(userStanding.start)
onUnmounted(userStanding.dispose)
// A merged account (HIL-1292): the shell's notice stands, the list stays to
// read, and nothing on this tab ends anything.
const merged = computed(() => hilosUserMergedNotice(standing.value) !== null)
const currentUserId = useSignal(sessionUserId(props.context.scopes))
const ownCard = computed(
  () => detail.value !== undefined && detail.value.id === currentUserId.value,
)

// The tabs are a live picture of the nodes, which a frozen copy may hold back;
// the sessions come from the database and do not lag (HIL-711).
const connectionState = useConnectionState(props.context.connection)
const staleness = useRtStaleness(props.context.connection)
const tabsMayLag = computed(
  () => connectionState.value === 'connected' && staleness.value.stale,
)
// The strip is one line at any width; its whole text opens behind a button, and
// a strip that went takes the open text with it.
const lagDetailOpen = ref(false)
watch(tabsMayLag, (mayLag) => {
  if (!mayLag) {
    lagDetailOpen.value = false
  }
})

// An ending takes its row out of the list, and the row's End with it; an
// emptied list turns End all sessions off. The server's answer and its update
// of the list come in either order. When the update comes first, the window has
// nowhere to give the focus back; when the answer comes first, the window gives
// it back to the End that the update takes away next. Either way the focus goes
// to End all sessions while it can be pressed, and to the tab's own section
// when it cannot.
const sessionsRoot = ref<HTMLElement | null>(null)
watch(
  sessionsRoot,
  (root, _previous, onCleanup) => {
    if (root !== null) {
      onCleanup(
        registerFocusReturnFallback(root, () => sessionsFocusFallback(root)),
      )
    }
  },
  { flush: 'post' },
)
// Read before the redraw, while what holds the focus is still drawn.
watch([sessions, merged], keepFocusInTab)

/**
 * Keep the focus in the tab across a redraw that takes away what holds it:
 * a row gone from the list, End all sessions turned off or gone. A focus moved
 * elsewhere meanwhile stays where it was put.
 */
function keepFocusInTab(): void {
  const root = sessionsRoot.value
  const held = root?.ownerDocument.activeElement
  if (
    !root ||
    !(held instanceof HTMLElement) ||
    held === root ||
    !root.contains(held)
  ) {
    return
  }
  void nextTick(() => {
    if (sessionsRoot.value !== root || !root.isConnected) {
      return
    }
    const active = root.ownerDocument.activeElement
    const lost =
      active === null ||
      active === root.ownerDocument.body ||
      (active === held && !focusableElements(root).includes(held))
    if (lost) {
      sessionsFocusFallback(root).focus({ preventScroll: true })
    }
  })
}

/**
 * Where the focus goes once what held it in the tab is gone — the opener of a
 * window, or the control the focus stood on.
 *
 * @param root The tab's section.
 */
function sessionsFocusFallback(root: HTMLElement): HTMLElement {
  const endAll = root.querySelector<HTMLButtonElement>(
    '[data-id="user-sessions-end-all"]',
  )

  return endAll !== null &&
    !endAll.disabled &&
    endAll.getAttribute('aria-disabled') !== 'true'
    ? endAll
    : root
}

const expanded = ref<ReadonlySet<number>>(new Set())

/**
 * Open or close the tabs of one session, kept by its id across server updates.
 *
 * @param sessionId The session whose tabs are toggled.
 */
function toggleTabs(sessionId: number): void {
  const next = new Set(expanded.value)
  if (next.has(sessionId)) {
    next.delete(sessionId)
  } else {
    next.add(sessionId)
  }
  expanded.value = next
}

/**
 * Move the focus into a window whose step changed under it.
 *
 * @param body The element inside the window's body.
 */
function focusWindow(body: HTMLElement | null): void {
  void nextTick(() => {
    const dialog = body?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  })
}

// One session (Flow: ОДНА СЕССИЯ). The window holds the person and the session
// of the row it was opened on, and the session as it was then, for its words
// once the row is gone.
const endAction = useTrackedAction()
const endTarget = ref<{
  userId: number
  session: HilosUserSession
} | null>(null)
const endProof = ref<HilosStepUpOpenOutcome>('skip')
// The row whose End waits for the server's word; a second press sends nothing.
const endOpening = ref<number | null>(null)
const endBody = ref<HTMLElement | null>(null)
const endStepUp = createHilosUserCardStepUp(props.context, () => {
  if (endProof.value === 'ask' && endTarget.value !== null) {
    endProof.value = 'skip'
  }
})
const endStepUpBusy = useSignal(endStepUp.step.busy)
const endStepUpRefusal = useSignal(endStepUp.step.refusal)
watch(endProof, () => focusWindow(endBody.value))
// The session still in this person's list; a hidden list proves nothing gone.
const endListed = computed(() => {
  const target = endTarget.value
  const list = sessions.value
  if (target === null || isHiddenValue(list)) {
    return undefined
  }

  return detail.value?.id === target.userId
    ? list.find((session) => session.id === target.session.id)
    : undefined
})
// From Confirm until the window has acted on the answer: closed on a success,
// kept on a refusal. The action stops being busy a step before its success
// closes the window.
const endSubmitted = ref(false)
// Not of its own ending: the answer and the list's update come in either order,
// so a row that leaves before the window has acted on the answer is the ending
// at work, not a session gone under the window.
const endGone = computed(
  () =>
    endTarget.value !== null &&
    !endSubmitted.value &&
    !isHiddenValue(sessions.value) &&
    endListed.value === undefined,
)
const endShown = computed(
  () => endListed.value ?? endTarget.value?.session ?? null,
)
const endError = computed(() =>
  endGone.value ? SESSION_GONE : endAction.error.value,
)
const endOpen = computed({
  get: () => endTarget.value !== null,
  set: (open: boolean) => {
    if (!open) closeEnd()
  },
})

/**
 * Open the window over one session, after the confirmation step when it asks.
 *
 * @param session The session of the row whose End was pressed.
 */
async function openEnd(session: HilosUserSession): Promise<void> {
  const userId = detail.value?.id
  if (
    userId === undefined ||
    endAction.busy.value ||
    endOpening.value !== null
  ) {
    return
  }
  endAction.clearError()
  endOpening.value = session.id
  endProof.value = await endStepUp.open('endSession')
  endOpening.value = null
  endTarget.value = { userId, session }
}

/** Send the step's proof; the window's own content follows a success. */
async function confirmEndStep(): Promise<void> {
  if (
    endProof.value === 'ask' &&
    (await endStepUp.step.confirm()) &&
    endTarget.value !== null
  ) {
    endProof.value = 'skip'
  }
}

function closeEnd(): void {
  if (!endAction.busy.value) endTarget.value = null
}

// The list follows the server: a success only closes the window.
async function submitEnd(): Promise<void> {
  const target = endTarget.value
  if (
    target === null ||
    endProof.value !== 'skip' ||
    endListed.value === undefined ||
    merged.value ||
    endAction.busy.value
  ) {
    return
  }
  endSubmitted.value = true
  if (
    await endAction.run(controller.endSession(target.userId, target.session.id))
  ) {
    closeEnd()
  }
  endSubmitted.value = false
}

// All sessions (Flow: ВСЕ СЕССИИ). The window holds the person; its number is
// the live list's.
const allAction = useTrackedAction()
const allTarget = ref<{ userId: number } | null>(null)
const allProof = ref<HilosStepUpOpenOutcome>('skip')
const allOpening = ref(false)
const allBody = ref<HTMLElement | null>(null)
const allStepUp = createHilosUserCardStepUp(props.context, () => {
  if (allProof.value === 'ask' && allTarget.value !== null) {
    allProof.value = 'skip'
  }
})
const allStepUpBusy = useSignal(allStepUp.step.busy)
const allStepUpRefusal = useSignal(allStepUp.step.refusal)
watch(allProof, () => focusWindow(allBody.value))
const allCount = computed<Hideable<number>>(() =>
  isHiddenValue(sessions.value) ? HIDDEN_VALUE : sessions.value.length,
)
const allActedAs = computed(
  () =>
    !isHiddenValue(sessions.value) &&
    sessions.value.some((session) => session.impersonated),
)
// From Confirm until the window has acted on the answer, as for one session.
const allSubmitted = ref(false)
// Not of its own ending: the list may empty before the window has acted on the
// answer.
const allGone = computed(
  () => allTarget.value !== null && !allSubmitted.value && allCount.value === 0,
)
const allError = computed(() =>
  allGone.value ? ALL_GONE : allAction.error.value,
)
const allOpen = computed({
  get: () => allTarget.value !== null,
  set: (open: boolean) => {
    if (!open) closeAll()
  },
})

/** Open the window over all the person's sessions, after the step when it asks. */
async function openAll(): Promise<void> {
  const userId = detail.value?.id
  if (userId === undefined || allAction.busy.value || allOpening.value) {
    return
  }
  allAction.clearError()
  allOpening.value = true
  allProof.value = await allStepUp.open('endAllSessions')
  allOpening.value = false
  allTarget.value = { userId }
}

/** Send the step's proof; the window's own content follows a success. */
async function confirmAllStep(): Promise<void> {
  if (
    allProof.value === 'ask' &&
    (await allStepUp.step.confirm()) &&
    allTarget.value !== null
  ) {
    allProof.value = 'skip'
  }
}

function closeAll(): void {
  if (!allAction.busy.value) allTarget.value = null
}

// The server ends every session current when it carries this out and says how
// many; that sentence, not the number shown here, is the success.
async function submitAll(): Promise<void> {
  const target = allTarget.value
  if (
    target === null ||
    allProof.value !== 'skip' ||
    allGone.value ||
    detail.value?.id !== target.userId ||
    merged.value ||
    allAction.busy.value
  ) {
    return
  }
  allSubmitted.value = true
  if (await allAction.run(controller.endAllSessions(target.userId))) {
    closeAll()
  }
  allSubmitted.value = false
}

// A merge that lands under an open window closes it: there is nothing left to
// end on the account. A window whose action is in flight stays for its answer.
watch(merged, (isMerged) => {
  if (isMerged) {
    closeEnd()
    closeAll()
  }
})
</script>

<template>
  <HilosUserPageShell :context="context" :page="HilosPages.USER_SESSIONS">
    <section
      v-if="detail"
      ref="sessionsRoot"
      tabindex="-1"
      aria-labelledby="hilos-user-sessions-heading"
      data-id="hilos-user-sessions-page"
    >
      <h2 id="hilos-user-sessions-heading" class="visually-hidden">Sessions</h2>
      <p class="small text-body-secondary">
        A session is a browser that signed in. Its open tabs are listed inside
        it.
      </p>
      <!-- The strip keeps its room whether or not it is shown, so the list
      under it does not move when the copy freezes or thaws: one line at any
      width, the whole text behind the button beside it (styling-rules.md, "The
      room a live message takes"). -->
      <div class="hilos-stack mb-3" role="status" aria-live="polite">
        <div
          class="alert alert-info small py-2 mb-0 invisible d-flex align-items-center gap-2"
          aria-hidden="true"
          inert
        >
          <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
          <span class="flex-grow-1 text-truncate">&nbsp;</span>
          <!-- A span, not a button: the twin holds room, it does not take focus. -->
          <span
            class="btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </span>
        </div>
        <div
          v-if="tabsMayLag"
          class="alert alert-info small py-2 mb-0 d-flex align-items-center gap-2"
          data-id="user-sessions-lag"
        >
          <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
          <span class="flex-grow-1 text-truncate">{{ TABS_MAY_LAG }}</span>
          <button
            type="button"
            class="btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap"
            aria-label="Show details"
            title="Show details"
            data-id="user-sessions-lag-details"
            @click="lagDetailOpen = true"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </button>
        </div>
      </div>
      <div class="border rounded-3 px-3 pt-2 pb-3 mb-3">
        <div v-if="isHiddenValue(sessions)" class="py-2">
          <HilosHiddenMark />
        </div>
        <p
          v-else-if="sessions.length === 0"
          class="text-body-secondary py-2 mb-0"
        >
          No sessions.
        </p>
        <div v-else>
          <HilosSessionRow
            v-for="session in sessions"
            :key="session.id"
            :session="session"
            :expanded="expanded.has(session.id)"
            id-prefix="user-session"
            :data-record="session.id"
            @toggle="toggleTabs(session.id)"
          >
            <template #badge>
              <span
                v-if="session.impersonatorName !== null"
                class="badge bg-warning-subtle text-warning-emphasis ms-1"
                >Acting as · <HilosHideable :value="session.impersonatorName"
              /></span>
            </template>
            <template v-if="!merged" #actions>
              <LoadingButton
                class="btn-sm btn-outline-danger"
                opens-window
                :loading="endOpening === session.id"
                :aria-label="`End session #${session.id}`"
                data-id="user-session-end"
                @click="openEnd(session)"
              >
                End
              </LoadingButton>
            </template>
          </HilosSessionRow>
        </div>
        <div v-if="!merged" class="d-flex flex-wrap gap-2 mt-3">
          <LoadingButton
            class="btn-sm btn-outline-danger"
            opens-window
            :loading="allOpening"
            :disabled="allCount === 0"
            data-id="user-sessions-end-all"
            @click="openAll"
          >
            End all sessions
          </LoadingButton>
        </div>
      </div>
    </section>
    <p v-else class="text-body-secondary">Loading sessions…</p>

    <template #modals>
      <HilosModal
        v-model="endOpen"
        :title="
          endProof === 'skip'
            ? `End session #${endTarget?.session.id ?? ''}`
            : HILOS_STEP_UP_COPY.title
        "
        initial-focus="inner"
        :busy="endAction.busy.value"
        @cancel="closeEnd"
      >
        <div ref="endBody">
          <div class="visually-hidden" role="alert" aria-live="assertive">
            {{ endProof === 'skip' ? endError : endStepUpRefusal }}
          </div>
          <form
            v-if="endProof !== 'skip'"
            id="hilos-user-session-end-proof"
            data-id="user-session-end-step-up"
            @submit.prevent="confirmEndStep()"
          >
            <HilosStepUpStep :controller="endStepUp.step" />
          </form>
          <template v-else-if="endShown !== null">
            <template v-if="endShown.impersonatorName !== null">
              <p>
                In this session
                <HilosHideable :value="endShown.impersonatorName" /> is acting
                as the person. Ending it takes this browser back to that
                administrator instead of the sign-in form.
              </p>
              <div class="alert alert-secondary small py-2">
                The person's own sessions are not touched.
              </div>
            </template>
            <template v-else>
              <p>
                Tabs of this session lose the account at once: where an account
                is needed, the sign-in form takes the place of the content.
              </p>
              <div class="alert alert-secondary small py-2">
                The person's other sessions are not touched: their other
                browsers keep working.
              </div>
            </template>
            <p v-if="ownCard">
              This is your own account: if you are using this session now, this
              tab loses the account too.
            </p>
            <HilosFormError
              :message="endError"
              data-id="user-session-end-error"
            />
          </template>
        </div>
        <template #actions="{ requestClose }">
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="endAction.busy.value"
            data-id="user-session-end-cancel"
            @click="requestClose"
          >
            Cancel
          </button>
          <LoadingButton
            v-if="endProof === 'ask'"
            class="btn-primary"
            type="submit"
            form="hilos-user-session-end-proof"
            :loading="endStepUpBusy"
            data-id="user-session-end-step-up-confirm"
            >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
          >
          <LoadingButton
            v-else-if="endProof === 'skip'"
            class="btn-danger"
            :loading="endAction.busy.value"
            :disabled="
              (endListed === undefined && !endAction.busy.value) || merged
            "
            data-id="user-session-end-confirm"
            @click="submitEnd"
            >End</LoadingButton
          >
        </template>
      </HilosModal>

      <HilosModal
        v-model="allOpen"
        :title="
          allProof !== 'skip'
            ? HILOS_STEP_UP_COPY.title
            : detail
              ? `End all sessions · ${hiddenAsWord(detail.name)}`
              : 'End all sessions'
        "
        initial-focus="inner"
        :busy="allAction.busy.value"
        @cancel="closeAll"
      >
        <div ref="allBody">
          <div class="visually-hidden" role="alert" aria-live="assertive">
            {{ allProof === 'skip' ? allError : allStepUpRefusal }}
          </div>
          <form
            v-if="allProof !== 'skip'"
            id="hilos-user-sessions-end-all-proof"
            data-id="user-sessions-end-all-step-up"
            @submit.prevent="confirmAllStep()"
          >
            <HilosStepUpStep :controller="allStepUp.step" />
          </form>
          <template v-else>
            <p>
              Sessions that will end:
              <strong><HilosHideable :value="allCount" /></strong>. The tabs of
              each lose the account at once: where an account is needed, the
              sign-in form takes the place of the content.
            </p>
            <p v-if="allActedAs">
              A session in which an administrator acts as the person takes that
              browser back to the administrator instead.
            </p>
            <div class="alert alert-secondary small py-2">
              The account itself is not touched: the person can sign in again at
              once. To close sign-in for longer, block the account.
            </div>
            <p v-if="ownCard">
              This is your own account: your current session ends too, and this
              tab loses the account.
            </p>
            <HilosFormError
              :message="allError"
              data-id="user-sessions-end-all-error"
            />
          </template>
        </div>
        <template #actions="{ requestClose }">
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="allAction.busy.value"
            data-id="user-sessions-end-all-cancel"
            @click="requestClose"
          >
            Cancel
          </button>
          <LoadingButton
            v-if="allProof === 'ask'"
            class="btn-primary"
            type="submit"
            form="hilos-user-sessions-end-all-proof"
            :loading="allStepUpBusy"
            data-id="user-sessions-end-all-step-up-confirm"
            >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
          >
          <LoadingButton
            v-else-if="allProof === 'skip'"
            class="btn-danger"
            :loading="allAction.busy.value"
            :disabled="allGone || detail?.id !== allTarget?.userId || merged"
            data-id="user-sessions-end-all-confirm"
            @click="submitAll"
            >End all</LoadingButton
          >
        </template>
      </HilosModal>

      <HilosModal
        v-model="lagDetailOpen"
        title="Details"
        initial-focus="dialog"
      >
        <HilosLongText
          kind="prose"
          :text="TABS_MAY_LAG"
          data-id="user-sessions-lag-full"
        />
        <template #actions="{ requestClose }">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="user-sessions-lag-close"
            @click="requestClose"
          >
            Close
          </button>
        </template>
      </HilosModal>
    </template>
  </HilosUserPageShell>
</template>
