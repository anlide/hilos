<!-- The channel Overview reads the summary from its page answer: configuration
counts and channel controls, with no fields table or editor. Enablement follows
only the server summary; tracked actions preserve the backend's refusal words
and the test-send toast. The shared channel head owns the heading and tabs. -->
<script setup lang="ts">
import { computedSignal, HilosPages, isHiddenValue } from '@hilos/core/base'
import {
  CHANNEL_ENABLED_FIELD,
  createHilosChannelSummary,
  createHilosCommunicationsActions,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'
import { inject, useId } from 'vue'

import HilosHideable from '../../HilosHideable.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import HilosCommunicationsChannelHead from './HilosCommunicationsChannelHead.vue'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosCommunicationsChannelPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const source = createHilosChannelSummary(props.context)
const summary = computedSignal(() => {
  const route = router.currentRoute.get()
  const current = source.get()

  return props.context.scopes.page()?.key ===
    HilosPages.COMMUNICATIONS_CHANNEL &&
    route.page === HilosPages.COMMUNICATIONS_CHANNEL &&
    current?.channel === route.params.channelId
    ? current
    : null
})
const channel = useSignal(summary)
const { sendChannelSet, sendChannelTest } = createHilosCommunicationsActions(
  props.context,
)
const { busy: toggleBusy, run: runToggle } = useTrackedAction()
const testAction = useTrackedAction()
const channelHeadingId = useId()
const enabledHintId = useId()

function toggleEnabled(next: boolean): void {
  const current = summary.get()
  if (!current || isHiddenValue(current.enabled) || toggleBusy.value) {
    return
  }
  void runToggle(sendChannelSet(current.channel, CHANNEL_ENABLED_FIELD, next))
}

function sendTest(): void {
  const current = summary.get()
  if (!current || testAction.busy.value) {
    return
  }
  void testAction.run(sendChannelTest(current.channel))
}
</script>

<template>
  <HilosCommunicationsChannelHead
    :page="HilosPages.COMMUNICATIONS_CHANNEL"
    :scopes="context.scopes"
    :summary="summary"
  >
    <template v-if="channel">
      <div class="row row-cols-1 row-cols-sm-3 g-3 mb-4">
        <div class="col">
          <div class="card h-100" data-id="channel-summary-configuration">
            <div class="card-body">
              <h2 class="small text-body-secondary mb-1">Configuration</h2>
              <div class="fs-4 fw-semibold mb-1">
                {{
                  channel.configured
                    ? 'Configured'
                    : `${channel.missingFields} missing`
                }}
              </div>
              <p class="small text-body-secondary mb-0">
                {{
                  channel.configured
                    ? 'every field has a value'
                    : 'fields hold a bare default'
                }}
              </p>
            </div>
          </div>
        </div>
        <div class="col">
          <div class="card h-100" data-id="channel-summary-fields">
            <div class="card-body">
              <h2 class="small text-body-secondary mb-1">Fields</h2>
              <div class="fs-4 fw-semibold mb-1">{{ channel.fieldCount }}</div>
              <p class="small text-body-secondary mb-0">
                {{
                  channel.secretFieldCount
                    ? `${channel.secretFieldCount} secret, set in env only`
                    : 'no secrets'
                }}
              </p>
            </div>
          </div>
        </div>
        <div class="col">
          <div class="card h-100" data-id="channel-summary-overridden">
            <div class="card-body">
              <h2 class="small text-body-secondary mb-1">Overridden here</h2>
              <div class="fs-4 fw-semibold mb-1">
                {{ channel.overriddenFieldCount }}
              </div>
              <p class="small text-body-secondary mb-0">
                {{
                  channel.overriddenFieldCount
                    ? 'reset returns them to env or the default'
                    : 'every value comes from env or the default'
                }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <section
        :aria-labelledby="channelHeadingId"
        class="border rounded-3 overflow-hidden mb-3"
        data-id="channel-controls"
      >
        <h2
          :id="channelHeadingId"
          class="small fw-semibold bg-body-tertiary border-bottom px-3 py-2 mb-0"
        >
          Channel
        </h2>
        <div class="p-3">
          <div
            class="d-flex flex-wrap justify-content-between align-items-center gap-3 border-bottom py-2"
          >
            <div>
              <h3 class="h6 mb-1">Enabled</h3>
              <p :id="enabledHintId" class="small text-body-secondary mb-0">
                When off, no new deliveries go out through it; queued ones still
                do.
              </p>
            </div>
            <HilosHideable :value="channel.enabled">
              <template #default="{ value }">
                <HilosSwitch
                  class="mb-0"
                  :checked="value"
                  :busy="toggleBusy"
                  :aria-describedby="enabledHintId"
                  aria-label="Enabled"
                  data-id="hilos-channel-enabled"
                  @toggle="toggleEnabled"
                />
              </template>
            </HilosHideable>
          </div>
          <div
            class="d-flex flex-wrap justify-content-between align-items-center gap-3 border-bottom py-2"
          >
            <div>
              <h3 class="h6 mb-1">Test notification</h3>
              <p class="small text-body-secondary mb-0">
                One notification to you, through this channel only.
              </p>
            </div>
            <LoadingButton
              class="btn-outline-primary btn-sm"
              :loading="testAction.busy.value"
              data-id="hilos-channel-test"
              @click="sendTest"
            >
              Send test notification
            </LoadingButton>
          </div>
        </div>
      </section>
    </template>
  </HilosCommunicationsChannelHead>
</template>
