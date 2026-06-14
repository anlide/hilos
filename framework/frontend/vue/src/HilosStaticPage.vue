<!-- HilosStaticPage — the tier-1 wrapper for a static, content-only framework
page (About / Terms / Privacy / License and the like). The framework owns the
page frame — a centered single reading column with a heading — and a project
fills the default slot with that page's prose, so the look stays uniform while
the content stays a project concern. Long content scrolls within the shell's
main region (HilosLayout). Styling is Bootstrap classes only, no CSS of its own
(styling-rules.md). -->
<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<template>
  <article class="row justify-content-center" data-id="static-page">
    <div class="col-12 col-lg-8">
      <h1 class="h3 mb-4" data-id="static-page-title">{{ title }}</h1>
      <slot />
    </div>
  </article>
</template>
