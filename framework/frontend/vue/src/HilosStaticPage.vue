<!-- HilosStaticPage — the frame for a static page: a centered reading column
with a heading, the project filling the body. It frames a project's own static
pages, and it is what the four public framework pages render inside themselves;
it is neither their component nor widened for their behavior. A project fills
the default slot with that page's prose, so the look stays uniform while the
content stays a project concern. Long content scrolls within the shell's main
region (HilosLayout). Styling is Bootstrap classes only, no CSS of its own
(styling-rules.md). No demo carries a static page of its own, because all four
static pages a demo shows belong to the framework; the frame stays exported for
a project that has one. -->
<script setup lang="ts">
import type {
  HilosPageIdentity,
  HilosStaticPageNavigation,
} from '@hilos/core/base'
import { computed, provide, useId } from 'vue'

import HilosPageHead from './HilosPageHead.vue'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'

const props = defineProps<{
  /** The page heading. */
  title: string
  /** A decorative Bootstrap icon beside the heading. */
  icon?: string
  /** The line under the heading. */
  subtitle?: string
  /** The page's optional section navigation. */
  navigation?: HilosStaticPageNavigation
}>()

const headingId = useId()
provide(hilosPageHeadingIdKey, headingId)

const page = computed(() => props.navigation?.page ?? '')
const identity = computed<HilosPageIdentity>(() => ({
  label: props.title,
  lead: props.subtitle ?? '',
  breadcrumb: [],
  children: [],
  pageHeadOwner: {
    page: props.navigation?.tabs[0]?.page ?? page.value,
    label: props.title,
    lead: props.subtitle ?? '',
    icon: props.icon ?? null,
  },
  pageTabs: [...(props.navigation?.tabs ?? [])],
}))
</script>

<template>
  <article class="row justify-content-center" data-id="static-page">
    <div class="col-12 col-lg-8">
      <HilosPageHead
        :identity="identity"
        :page="page"
        :params="navigation?.params ?? {}"
        :resolve-path="navigation?.resolvePath ?? (() => undefined)"
        :heading-id="headingId"
        kind="section"
        :tabs-label="navigation?.label ?? 'Page sections'"
      />
      <slot />
    </div>
  </article>
</template>
