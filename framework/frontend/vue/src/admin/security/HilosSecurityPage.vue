<!-- The first page answer supplies all five read-only Security summaries.
No table windows are opened for these directory-wide aggregates. -->
<script setup lang="ts">
import {
  createHilosSecurityOverview,
  HILOS_IMPERSONATION_SCOPE_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  type HilosSecurityOverviewContext,
} from '@hilos/core/admin/security'
import { HilosPages } from '@hilos/core/base'

import HilosHideable from '../../HilosHideable.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import { useSignal } from '../../useSignal.js'
import HilosSecurityPageShell from './HilosSecurityPageShell.vue'

const props = defineProps<{
  /** The project's current page scopes. */
  context: HilosSecurityOverviewContext
}>()

const overview = useSignal(createHilosSecurityOverview(props.context))
</script>

<template>
  <HilosSecurityPageShell :page="HilosPages.SECURITY">
    <div data-id="security-overview">
      <div
        v-if="overview"
        class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-2"
      >
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="security-overview-two-factor"
          >
            <h2 class="small text-body-secondary fw-normal">Two-factor</h2>
            <HilosHideable :value="overview.twoFactor.enabled">
              <template #default="{ value: enabled }">
                <template v-if="enabled">
                  <HilosHideable :value="overview.twoFactor.required">
                    <template #default="{ value: required }">
                      <div class="fs-4 fw-semibold lh-sm my-1">
                        {{ HILOS_SECOND_FACTOR_REQUIRED_COPY[required] }}
                      </div>
                      <div class="small text-body-secondary">
                        Required; others can enable it in their profile.
                      </div>
                    </template>
                  </HilosHideable>
                </template>
                <template v-else>
                  <div class="fs-4 fw-semibold lh-sm my-1">Disabled</div>
                  <div class="small text-body-secondary">
                    No code is requested at sign-in; people's apps and codes are
                    preserved.
                  </div>
                </template>
              </template>
            </HilosHideable>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="security-overview-step-up"
          >
            <h2 class="small text-body-secondary fw-normal">
              Operations that ask for confirmation
            </h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosHideable :value="overview.stepUp.enabledCount" />
              of {{ overview.stepUp.totalCount }}
            </div>
            <div class="small text-body-secondary">
              Operations requiring confirmation
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="security-overview-oauth"
          >
            <h2 class="small text-body-secondary fw-normal">OAuth providers</h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              {{ overview.oauth.configuredCount }} of
              {{ overview.oauth.totalCount }}
            </div>
            <div class="small text-body-secondary">Configured</div>
            <!-- One line, even when clear. Every warning stays whole and can
            be scrolled with the keyboard, without moving the next row. -->
            <div
              class="small d-flex gap-3 overflow-x-scroll text-nowrap"
              :tabindex="overview.oauth.unconfigured.length ? 0 : undefined"
              :aria-hidden="
                overview.oauth.unconfigured.length === 0 ? true : undefined
              "
              role="region"
              aria-label="OAuth configuration warnings"
            >
              <span
                v-for="provider in overview.oauth.unconfigured"
                :key="provider.providerKey"
                class="text-warning-emphasis"
              >
                <i
                  class="bi bi-exclamation-triangle me-1"
                  aria-hidden="true"
                ></i>
                {{ provider.label }}: {{ provider.missingFields }} required
                field(s) not set
              </span>
              <span
                v-if="overview.oauth.unconfigured.length === 0"
                class="invisible"
                aria-hidden="true"
                inert
                ><i
                  class="bi bi-exclamation-triangle me-1"
                  aria-hidden="true"
                ></i
                >&nbsp;</span
              >
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="security-overview-sign-in-methods"
          >
            <h2 class="small text-body-secondary fw-normal">Sign-in methods</h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosHideable :value="overview.signInMethods.enabledCount" />
              of {{ overview.signInMethods.totalCount }}
            </div>
            <div class="small text-body-secondary">Enabled</div>
            <HilosHideable :value="overview.signInMethods.unready">
              <template #default="{ value: unready }">
                <div
                  class="small d-flex gap-3 overflow-x-scroll text-nowrap"
                  :tabindex="unready.length ? 0 : undefined"
                  :aria-hidden="unready.length === 0 ? true : undefined"
                  role="region"
                  aria-label="Sign-in delivery warnings"
                >
                  <span
                    v-for="method in unready"
                    :key="method.methodKey"
                    class="text-warning-emphasis"
                  >
                    <i
                      class="bi bi-exclamation-triangle me-1"
                      aria-hidden="true"
                    ></i>
                    {{ method.label }} has nothing to send with
                  </span>
                  <span
                    v-if="unready.length === 0"
                    class="invisible"
                    aria-hidden="true"
                    inert
                    ><i
                      class="bi bi-exclamation-triangle me-1"
                      aria-hidden="true"
                    ></i
                    >&nbsp;</span
                  >
                </div>
              </template>
            </HilosHideable>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="security-overview-impersonation"
          >
            <h2 class="small text-body-secondary fw-normal">Impersonation</h2>
            <HilosHideable :value="overview.impersonation.allowed">
              <template #default="{ value: allowed }">
                <div class="fs-4 fw-semibold lh-sm my-1">
                  {{ allowed ? 'Allowed' : 'Disabled' }}
                </div>
                <div v-if="allowed" class="small text-body-secondary">
                  <HilosHideable :value="overview.impersonation.scope">
                    <template #default="{ value: scope }">
                      {{ HILOS_IMPERSONATION_SCOPE_COPY[scope] }}
                    </template>
                  </HilosHideable>
                </div>
              </template>
            </HilosHideable>
          </section>
        </div>
      </div>
      <HilosSkeleton v-else />
    </div>
  </HilosSecurityPageShell>
</template>
