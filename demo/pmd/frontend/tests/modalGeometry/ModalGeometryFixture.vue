<!-- The modal's own geometry stand: a test-only page with no server behind it.
The page is taller than the window on every screen of the norm, and the modal
shows what a process printed — a first line with nowhere to wrap, then more
lines than any of those screens can hold — so the spec can ask whether the
dialog keeps its head and foot on screen, scrolls its body, and never scrolls
sideways. -->
<script setup lang="ts">
import { ref } from 'vue'
import { HilosLongText, HilosModal } from '@hilos/vue/base'

/** Enough ordinary paragraphs for the page to outgrow the window on every screen. */
const PAGE_PARAGRAPHS = 60

/** How many short lines follow the unbroken one. */
const OUTPUT_STEPS = 80

/** A 400-character hex digest: one word, with no place to break it. */
const DIGEST = '0123456789abcdef'.repeat(25)

/** The process output: an unbroken first line, then more lines than fit. */
const OUTPUT = [
  `sha256:${DIGEST}`,
  ...Array.from(
    { length: OUTPUT_STEPS },
    (_, index) => `step ${String(index + 1).padStart(2, '0')}: ok`,
  ),
].join('\n')

const open = ref(false)
</script>

<template>
  <main class="container py-3" data-id="modal-geometry-fixture">
    <h1 class="h4">Modal geometry fixture</h1>
    <button
      type="button"
      class="btn btn-primary"
      data-id="modal-geometry-open"
      @click="open = true"
    >
      Open modal
    </button>
    <p v-for="n in PAGE_PARAGRAPHS" :key="n">
      Paragraph {{ n }} of the page behind the dialog. It is ordinary text that
      wraps between words, and there is enough of it for the page to stand
      taller than the window.
    </p>
    <HilosModal
      v-model="open"
      title="Why the run failed"
      initial-focus="dialog"
      :copy-text="OUTPUT"
    >
      <HilosLongText
        kind="output"
        :text="OUTPUT"
        data-id="modal-geometry-output"
      />
      <p class="mb-0" data-id="modal-geometry-body-end">End of output</p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          data-id="modal-geometry-close"
          @click="requestClose"
        >
          Close
        </button>
      </template>
    </HilosModal>
  </main>
</template>
