<!-- HilosPrivacyPage — the tier-2 public /privacy page: the project's own privacy
prose (the default slot) and, under it, the one thing that exists nowhere else in
the product — erase everything this browser keeps and end its session.

The block is the same for a guest and for a signed-in person, deliberately. A
browser with no account still holds things worth erasing, and "sign out" is
literally true for it too: what the erase ends is the BROWSER SESSION, which a
guest has like anybody else.

The list is not typed by hand. It is the registry of declared browser values
(@hilos/core, browser/browserValue.ts) — the framework's own plus whatever the
project hands in — and the confirmation names its entries one by one, so the
person agrees to a list rather than to an adjective.

THE ORDER IS LOAD-BEARING: the browser half runs first and the server half
second. The rotation ticket the server answers with rides a cookie the framework
writes into this browser, and that cookie is itself a registry entry — a sweep
running after the dispatch would delete the one value the new session arrives on,
silently, leaving the old cookie in place. The plainer reason is the second one:
the browser half cannot fail and the server half can, so doing the fallible thing
second means the person never loses the certain part to the uncertain one.

The page carries no `title` prop: the heading moves with the frame, and the
project's file holds prose alone. Nothing here is read at render time — a
declaration is data and the sweep is a click handler — which is what lets
/privacy be prerendered with this block whole and inert until the SPA mounts.
Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  BROWSER_ERASE_ACTION,
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
  type ActionLifecycle,
  type HilosBrowserValue,
  type HilosConnection,
} from '@hilos/core'

import HilosStaticPage from '../HilosStaticPage.vue'
import { useTrackedAction } from '../useTrackedAction.js'
import HilosPrivacyEraseModal from './HilosPrivacyEraseModal.vue'

const props = withDefaults(
  defineProps<{
    /** The connection whose session is ended, and whose in-memory pass is dropped. */
    connection: HilosConnection
    /** The reply lifecycle the erase is dispatched on — the app's one instance. */
    actions: ActionLifecycle
    /** The project's own declarations, swept beside the framework's. */
    values?: readonly HilosBrowserValue[]
  }>(),
  { values: () => [] },
)

/** Everything this browser is declared to hold: the framework's list plus the project's. */
const registry = computed(() => [...HILOS_BROWSER_VALUES, ...props.values])

/** One line per registry entry, in declaration order, as the confirmation shows them. */
const labels = computed(() => registry.value.map((value) => value.label))

const erase = useTrackedAction()
const { busy, loading, error } = erase

const confirming = ref(false)

/** What the erase did, once it has run; null while the block is still an offer. */
const swept = ref<string[] | null>(null)

// The modal is brought in only once there is a browser to hold it, exactly as the
// licence page brings its own in: this page is also rendered at build time by a
// server renderer where `document` does not exist, and HilosModal reads the
// document the moment it is mounted. A closed modal teleports nothing anyway.
const browserReady = ref(false)
onMounted(() => {
  browserReady.value = true
})

async function onConfirm(): Promise<void> {
  if (busy.value) {
    return
  }

  // 1. The browser half, first and synchronous. It cannot fail: a storage that is
  // absent or refuses is reported as nothing swept rather than as an error.
  const erased = eraseBrowserValues(registry.value, {
    sessionCookieName: props.connection.sessionCookieName,
  })

  // 2. The pass the connection also holds IN MEMORY and re-presents on every
  // reconnect. Erasing only its mirror in storage would buy the admission back on
  // the socket that comes next.
  props.connection.forgetProtectedModePass()

  // 3. The server half. The confirmation stays open with its button loading until
  // this settles, and only then does the block become the outcome — either way,
  // success or refusal. Drawing the outcome before the reply would state that this
  // browser's session was ended while the question was still on the wire, and on a
  // timeout it would state it wrongly for thirty seconds.
  await erase.run(props.actions.dispatch(BROWSER_ERASE_ACTION, {}))
  confirming.value = false
  swept.value = erased
}
</script>

<template>
  <HilosStaticPage title="Privacy">
    <slot />

    <h2 class="h6 text-uppercase text-body-secondary mb-2 mt-4">
      Erase everything stored on this device
    </h2>

    <template v-if="swept === null">
      <p class="small text-body-secondary">
        Signs you out and clears every cookie and local value this site has kept
        — as if you had never opened it.
      </p>
      <!-- Never disabled, in any state, including with the socket down: a control
      that is off and cannot say why is the defect HIL-661 exists to fix, and the
      browser half runs offline just as well. -->
      <button
        type="button"
        class="btn btn-outline-danger btn-sm"
        data-id="privacy-erase"
        @click="confirming = true"
      >
        <i class="bi bi-eraser me-1" aria-hidden="true"></i>
        Erase and sign out
      </button>
    </template>

    <div v-else data-id="privacy-erase-done">
      <p class="small mb-2">
        {{ swept.length }} of the things this browser kept were erased.
      </p>
      <ul class="small text-body-secondary mb-2 ps-3">
        <li v-for="label in swept" :key="label">{{ label }}</li>
      </ul>
      <p v-if="error === null" class="small mb-2">
        This browser's session was ended and a new one begun, so the sign-in
        cookie is not the one it arrived with. Other tabs come back on the new
        one, signed out.
      </p>
      <p v-else class="small mb-2" data-id="privacy-erase-partial">
        The values above are gone, but this browser's session could not be
        ended: {{ error }}
      </p>
      <p class="small text-body-secondary mb-0">
        Nothing about your account changed. This is not account deletion.
      </p>
    </div>

    <HilosPrivacyEraseModal
      v-if="browserReady"
      v-model="confirming"
      :labels="labels"
      :busy="busy"
      :loading="loading"
      @confirm="onConfirm"
    />
  </HilosStaticPage>
</template>
