<!-- HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
lead common to every Hilos admin page, plus the cards to the page's children. A
page passes only its key (props.page); the shell reads the live route params from
the navigator to keep the breadcrumb and child links in context, and takes the
heading, the lead, the chain and the subsection cards from the navigator's
pageIdentity — which is what the page's own subscription answered with, not a
frontend constant. It is page-agnostic — it renders whichever key it is given,
never choosing the page itself (that is the app shell's page→view map).

A page whose answer names a head owner (pageHeadOwner: its owner opted in to the
shared head) gets HilosPageHead in place of all that — the breadcrumb, the owner's
block and its one row of tabs, and no heading, lead or cards of the shell's own
(docs/agents/architecture/page-head.md). The answer alone decides it: the head
props and the head-* slots only dress the owner's block, and on a page without an
owner they are ignored. Until an answer arrives, a page that names its head kind
already stands on that head's skeleton rather than on the legacy one.

Under the legacy heading the shell always draws the section's sub-navigation
cards — one for every child the live params can resolve — and a page's own
content goes in the one default slot, drawn beneath them: the way on stands above
what the admin came for, and no page's content can cost its children their cards.
A leaf that passes no content gets a stub empty-state instead.

While the identity is still on the wire the heading is a neutral placeholder and
neither the cards nor the stub is drawn; a page's own content already is, and the
cards rise above it once the identity lands. The raw page key is never printed,
and an empty h1 under the same data-id would make "the name did not arrive" look
exactly like "the name arrived empty".

The heading — the legacy one or the shared head's — carries an id the shell
provides to what it holds: a table that declares no title of its own takes its
accessible name from this heading, which already names it.

The shell also tells what it holds whether a viewer of the admin view mode
stands here (hilosAdminAccess is 'view', HIL-1253): the controls of the mode
read that themselves and stand plainly disabled (HIL-1261).

A page whose resource can be deleted under it opts in with
preserveModalDraftsOnDelete (HIL-1603) and puts its dialogs in the #modals slot.
Then the current page error replaces the head, the cards and the page's content
with one ErrorPage, while #modals stays on the same mount, under the same heading
and view-mode providers, so an open dialog keeps its draft above the 404. Inside
the routed outlet the shell registers, once mounted, to hold its page through a
live deletion (hilosPageErrorBoundaryKey); outside one it simply draws the error.
Its root offers the dialogs' focus return a fallback, the Back to home link of
the error drawn here, for an opener that went away with the content. Without the
opt-in nothing changes: the outlet replaces the whole page on any refusal.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  hilosAdminAccess,
  hilosChildLinks,
  hilosCrumbLinks,
  type HilosPageHeadAction,
  registerFocusReturnFallback,
} from '@hilos/core/base'
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  provide,
  ref,
  useId,
} from 'vue'

import ErrorPage from './ErrorPage.vue'
import HilosBreadcrumb from './HilosBreadcrumb.vue'
import HilosLink from './HilosLink.vue'
import HilosPageHead from './HilosPageHead.vue'
import { hilosAdminViewModeKey } from './hilosAdminViewMode.js'
import { hilosPageErrorBoundaryKey } from './hilosPageErrorBoundaryKey.js'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'
import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  page: string
  /** Extra context for child cards; breadcrumbs keep the current route params. */
  childParams?: Record<string, string>
  /**
   * The shared head's kind: a section head or an entity block. It does not opt
   * the owner in; it only lets the skeleton stand before the answer arrives.
   */
  headKind?: 'section' | 'entity'
  /** The one transition the shared head offers as a button. */
  headAction?: HilosPageHeadAction
  /** Extra presentation classes on the shared head's one h1. */
  headTitleClass?: string
  /** The shared head's breadcrumb navigation's accessible name. */
  breadcrumbLabel?: string
  /** The shared head's tab row navigation's accessible name. */
  tabsLabel?: string
  /**
   * Draw the page error in place of the content and keep #modals mounted under
   * it, so a dialog open when the page's resource is deleted keeps its draft.
   * Read once, when the shell is created: a page opts in for its whole mount.
   */
  preserveModalDraftsOnDelete?: boolean
}>()

defineSlots<{
  /** The page's own content, beneath the head. */
  default?(): unknown
  /** The shared head's block picture: an entity's tile or avatar. */
  'head-visual'?(): unknown
  /** The shared head's loading picture before the page answers with its owner. */
  'head-skeleton-visual'?(): unknown
  /** A short mark beside the shared head's title, such as the entity's code. */
  'head-meta'?(): unknown
  /** Status badges beside the shared head's title. */
  'head-badges'?(): unknown
  /** The line under the shared head's title. */
  'head-description'?(): unknown
  /** The page's dialogs: the one part an opted-in page error leaves mounted. */
  modals?(): unknown
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosAdminPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const resolvePath = router.resolvePath

const headingId = useId()
provide(hilosPageHeadingIdKey, headingId)
const adminAccess = useSignal(hilosAdminAccess)
provide(
  hilosAdminViewModeKey,
  computed(() => adminAccess.value === 'view'),
)

const route = useSignal(router.currentRoute)
const identity = useSignal(router.pageIdentity)
const params = computed(() => route.value.params)
// The answer's owner opts the page in; before the answer, the kind the page
// names picks the shared head's skeleton over the legacy one.
const sharedHead = computed(() =>
  identity.value === undefined
    ? props.headKind !== undefined
    : identity.value.pageHeadOwner !== undefined,
)
const crumbs = computed(() =>
  hilosCrumbLinks(identity.value?.breadcrumb ?? [], params.value, resolvePath),
)
const children = computed(() =>
  sharedHead.value
    ? []
    : hilosChildLinks(
        identity.value?.children ?? [],
        { ...params.value, ...props.childParams },
        resolvePath,
      ),
)

const retainForResourceDeletion = inject(hilosPageErrorBoundaryKey, undefined)
// Without the opt-in the shell never draws a refusal and reads nothing it did
// not read before: the outlet replaces the whole page with its own ErrorPage,
// as it always has.
const pageError = props.preserveModalDraftsOnDelete
  ? useSignal(router.pageError)
  : null
const refusal = computed(() => pageError?.value ?? null)
const root = ref<HTMLElement | null>(null)
const errorPage = ref<InstanceType<typeof ErrorPage> | null>(null)
const releases: (() => void)[] = []
// Only once mounted: the outlet holds the component it shows at the moment of
// registration, and a shell that registered earlier would match nothing. The
// focus fallback is offered before any dialog of the page can open, and lives
// and dies with this root.
onMounted(() => {
  if (!props.preserveModalDraftsOnDelete || root.value === null) {
    return
  }
  releases.push(
    registerFocusReturnFallback(
      root.value,
      () => errorPage.value?.homeLink() ?? null,
    ),
  )
  if (retainForResourceDeletion) {
    releases.push(retainForResourceDeletion(props.page))
  }
})
onUnmounted(() => {
  for (const release of releases.splice(0)) {
    release()
  }
})
</script>

<template>
  <section ref="root" data-id="hilos-admin-page" :data-page="page">
    <ErrorPage v-if="refusal" ref="errorPage" :error="refusal" />
    <template v-else>
      <HilosPageHead
        v-if="sharedHead"
        :identity="identity"
        :page="page"
        :params="params"
        :resolve-path="resolvePath"
        :heading-id="headingId"
        :kind="headKind"
        :action="headAction"
        :title-class="headTitleClass"
        :breadcrumb-label="breadcrumbLabel"
        :tabs-label="tabsLabel"
      >
        <template v-if="$slots['head-skeleton-visual']" #skeleton-visual>
          <slot name="head-skeleton-visual" />
        </template>
        <template v-if="$slots['head-visual']" #visual>
          <slot name="head-visual" />
        </template>
        <template v-if="$slots['head-meta']" #meta>
          <slot name="head-meta" />
        </template>
        <template v-if="$slots['head-badges']" #badges>
          <slot name="head-badges" />
        </template>
        <template v-if="$slots['head-description']" #description>
          <slot name="head-description" />
        </template>
      </HilosPageHead>
      <template v-else-if="identity">
        <HilosBreadcrumb :crumbs="crumbs" />
        <h1 :id="headingId" class="h4 mb-1" data-id="hilos-admin-title">
          {{ identity.label }}
        </h1>
        <p v-if="identity.lead" class="text-body-secondary">
          {{ identity.lead }}
        </p>
      </template>
      <div
        v-else
        class="placeholder-glow mb-3"
        data-id="hilos-admin-title-skeleton"
      >
        <span class="placeholder col-3 d-block mb-2 rounded"></span>
        <span class="placeholder col-6 d-block rounded"></span>
      </div>

      <div
        v-if="children.length"
        class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4"
        data-id="hilos-admin-children"
      >
        <div v-for="child in children" :key="child.page" class="col">
          <HilosLink
            :to="child.to"
            class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
            :data-id="`hilos-admin-child-${child.page}`"
          >
            <div class="card-body d-flex flex-column gap-1">
              <span
                class="h6 mb-0 d-flex align-items-center justify-content-between gap-2"
              >
                <span>{{ child.label }}</span>
                <i
                  class="bi bi-chevron-right text-body-secondary"
                  aria-hidden="true"
                ></i>
              </span>
              <span class="small text-body-secondary">{{ child.lead }}</span>
            </div>
          </HilosLink>
        </div>
      </div>

      <slot>
        <div
          v-if="!children.length && identity"
          class="border rounded p-4 text-center text-body-secondary"
          data-id="hilos-admin-empty"
        >
          <i
            class="bi bi-cone-striped fs-2 d-block mb-2"
            aria-hidden="true"
          ></i>
          <p class="mb-0">
            Stub page — real content arrives with this section's implementation.
          </p>
        </div>
      </slot>
    </template>
    <!-- After the branch, never inside it: switching to the error keeps this
    slot mounted, and the dialogs teleport to <body> wherever it stands. -->
    <slot name="modals" />
  </section>
</template>
