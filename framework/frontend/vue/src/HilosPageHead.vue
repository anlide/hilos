<!-- HilosPageHead — the shared head of a page whose owner opted in: the
breadcrumb, the owner's block (a section head or an entity block) with an
optional transition button on its right, and the owner's one row of page tabs
(docs/agents/architecture/page-head.md). It is page-agnostic: it reads the
identity the page answered with and nothing else, so it knows no concrete page,
and the page module hands it the block's content through the slots.

Only the block's content is a slot. The title is not: the one h1 shows the
owner's label, the live instance name already laid into it by the core, and it
carries the id the caller hands in (a table without a title of its own takes its
accessible name from it). A section draws its catalog icon in a circle and its
lead by default; an entity draws what its page supplies. The only button is the
one transition `action` names — no slot of free buttons, no edit or delete here.

The breadcrumb is drawn only from two links up and the row only from two tabs up,
counted after the route map has dropped the tabs this application has no address
for. The current tab is the one whose page key is the rendered page: not a
label, a path prefix or a position, so a child of a tab marks itself and not its
parent. Its aria-current is passed explicitly, so the link's own path match
cannot mark another tab while the router is already elsewhere.

Until the answer arrives — or an identity without an owner, which is not a head
this component draws — only a skeleton stands: no crumbs, no row, no name, no
slot content, no button. Its bars are decorative; the routed outlet announces
the wait once for the whole page.

On a narrow screen the row scrolls sideways and the current tab is centered in
the row's scroll limits: once after mounting, when the current key or the row's
composition changes, and when the row's box is resized — never on another
render, so a manual scroll or a rename does not snap it back.
Bootstrap classes and the SDK's own utilities only (styling-rules.md). -->
<script setup lang="ts">
import {
  hilosCrumbLinks,
  hilosTabLinks,
  type HilosPageHeadAction,
  type HilosPageIdentity,
  type HilosPathResolver,
} from '@hilos/core/base'
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue'

import HilosBreadcrumb from './HilosBreadcrumb.vue'
import HilosLink from './HilosLink.vue'

const props = withDefaults(
  defineProps<{
    /** What the page answered with; absent while the answer is on the wire. */
    identity?: HilosPageIdentity
    /** The key of the rendered page; the tab carrying it is current. */
    page: string
    /** The current route params every link is resolved in. */
    params: Record<string, string>
    /** Resolves a page key to its path (`HilosRouter.resolvePath`). */
    resolvePath: HilosPathResolver
    /** The id the h1 carries, from the caller; a local one when omitted. */
    headingId?: string
    /** A section head or an entity block: the defaults and the skeleton's tile. */
    kind?: 'section' | 'entity'
    /** The breadcrumb navigation's accessible name. */
    breadcrumbLabel?: string
    /** The tab row navigation's accessible name, named after the owner. */
    tabsLabel?: string
    /** The one transition the head offers as a button. */
    action?: HilosPageHeadAction
  }>(),
  {
    identity: undefined,
    headingId: undefined,
    kind: 'section',
    breadcrumbLabel: 'Breadcrumb',
    tabsLabel: 'Page sections',
    action: undefined,
  },
)

defineSlots<{
  /** The block's picture: an entity's tile or avatar; a section's icon by default. */
  visual?(): unknown
  /** A short mark beside the title, such as the entity's code. */
  meta?(): unknown
  /** Status badges beside the title. */
  badges?(): unknown
  /** The line under the title; a section's lead by default. */
  description?(): unknown
}>()

const localHeadingId = useId()
const titleId = computed(() => props.headingId ?? localHeadingId)

const owner = computed(() => props.identity?.pageHeadOwner)
const crumbs = computed(() =>
  hilosCrumbLinks(
    props.identity?.breadcrumb ?? [],
    props.params,
    props.resolvePath,
  ),
)
const tabs = computed(() =>
  hilosTabLinks(
    props.identity?.pageTabs ?? [],
    props.params,
    props.resolvePath,
  ),
)
const actionTo = computed(() =>
  props.action === undefined
    ? undefined
    : props.resolvePath(props.action.page, props.action.params ?? props.params),
)

const row = ref<HTMLElement | null>(null)
// The keys alone, so a new caption or a rename that leaves the row as it was
// does not re-center it over a scroll the reader made by hand.
const rowKeys = computed(() => tabs.value.map((tab) => tab.page).join('\n'))

/**
 * Scroll the row so the current tab stands in its middle, within the row's
 * scroll limits; a row that is not drawn, detached, or without a current tab is
 * left alone. The jump is instant: nothing animates and nothing takes focus.
 */
function centerCurrentTab(): void {
  const container = row.value
  if (container === null || !container.isConnected) {
    return
  }
  const current = container.querySelector<HTMLElement>('[aria-current="page"]')
  if (current === null) {
    return
  }
  const box = container.getBoundingClientRect()
  const tab = current.getBoundingClientRect()
  const target =
    container.scrollLeft +
    (tab.left - box.left) -
    (container.clientWidth - tab.width) / 2
  const limit = Math.max(0, container.scrollWidth - container.clientWidth)
  container.scrollLeft = Math.min(Math.max(target, 0), limit)
}

onMounted(() => {
  void nextTick(centerCurrentTab)
})
watch([() => props.page, rowKeys], () => {
  void nextTick(centerCurrentTab)
})
watch(row, (container, _previous, onCleanup) => {
  if (container === null || typeof ResizeObserver === 'undefined') {
    return
  }
  const observer = new ResizeObserver(centerCurrentTab)
  observer.observe(container)
  onCleanup(() => observer.disconnect())
})
</script>

<template>
  <div class="min-w-0" data-id="hilos-page-head">
    <template v-if="owner">
      <HilosBreadcrumb
        v-if="crumbs.length > 1"
        class="text-break"
        :crumbs="crumbs"
        :label="breadcrumbLabel"
      />
      <div
        class="d-flex align-items-start gap-3"
        :class="tabs.length > 1 ? 'mb-3' : 'mb-4'"
        data-id="hilos-page-head-block"
      >
        <slot name="visual">
          <span
            v-if="kind === 'section' && owner.icon"
            class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
            aria-hidden="true"
          >
            <i :class="['bi', owner.icon]"></i>
          </span>
        </slot>
        <div
          class="d-flex flex-wrap align-items-start column-gap-3 row-gap-2 flex-grow-1 min-w-0"
        >
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1
                :id="titleId"
                class="h4 mb-0 text-break"
                data-id="hilos-page-head-title"
              >
                {{ owner.label }}
              </h1>
              <slot name="meta" />
              <slot name="badges" />
            </div>
            <slot name="description">
              <p
                v-if="kind === 'section' && owner.lead"
                class="text-body-secondary mb-0"
              >
                {{ owner.lead }}
              </p>
            </slot>
          </div>
          <HilosLink
            v-if="action && actionTo !== undefined"
            :to="actionTo"
            class="btn btn-sm btn-outline-secondary text-nowrap"
            data-id="hilos-page-head-action"
            >{{ action.label }}</HilosLink
          >
        </div>
      </div>
      <nav
        v-if="tabs.length > 1"
        :aria-label="tabsLabel"
        class="mb-4 min-w-0"
        data-id="hilos-page-tabs"
      >
        <ul
          ref="row"
          class="nav nav-underline flex-nowrap overflow-x-auto border-bottom"
        >
          <li
            v-for="tab in tabs"
            :key="tab.page"
            class="nav-item flex-shrink-0"
          >
            <HilosLink
              :to="tab.to"
              class="nav-link text-nowrap"
              :class="{ active: tab.page === page }"
              :aria-current="tab.page === page ? 'page' : undefined"
              :data-id="`hilos-page-tab-${tab.page}`"
              >{{ tab.label }}</HilosLink
            >
          </li>
        </ul>
      </nav>
    </template>
    <div
      v-else
      class="d-flex align-items-start gap-3 mb-4 placeholder-glow"
      aria-hidden="true"
      data-id="hilos-page-head-skeleton"
    >
      <span
        class="hilos-avatar-md bg-secondary-subtle flex-shrink-0"
        :class="kind === 'entity' ? 'rounded-3' : 'rounded-circle'"
      ></span>
      <div class="flex-grow-1 min-w-0">
        <span
          class="placeholder placeholder-lg col-4 d-block mb-2 rounded"
        ></span>
        <span class="placeholder col-9 d-block rounded"></span>
      </div>
    </div>
  </div>
</template>
