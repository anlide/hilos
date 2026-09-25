<!-- HilosAuthStepTail — the room under the main button of a sign-in step.
Every step of the sign-in surface ends in its main button and a tail under it:
a resend, a way back, a switch to a backup code, the other channels a code
could go over. The tails differ from step to step, and the surface holds the
main button at one height across the steps (styling-rules.md, "The room a step
takes") — so what stands under the button has to be as tall on every step as
the tallest tail is. This is that room: the live tail of the step and an
invisible twin of the tallest tails, stacked into one grid cell. The twin is
the same on every step, which is what keeps the button still; it carries no
data-id, no button and no form control, so no locator, focus trap or reader
finds it. The `data-id` of the room itself comes from the place that mounts
it — a live step names it `auth-step-tail`, a twin of a step names nothing. -->
<script setup lang="ts">
/**
 * The tails that can turn out tallest, and the twin holds the room of both:
 * three link rows (the second-factor step: the switch to a backup code, the
 * way to a removal, Back) and the other channels of a phone (the divider and
 * the icon row). One icon is enough — the row is a flex of equally tall
 * things, so their number is not its height.
 */
const LINK_ROW_CLASS = 'btn btn-link btn-sm w-100'
</script>

<template>
  <div class="hilos-stack mt-2">
    <div class="d-flex flex-column"><slot /></div>
    <div class="invisible" aria-hidden="true">
      <div class="hilos-stack">
        <div class="d-flex flex-column">
          <span :class="LINK_ROW_CLASS">&nbsp;</span>
          <span :class="LINK_ROW_CLASS">&nbsp;</span>
          <span :class="LINK_ROW_CLASS">&nbsp;</span>
        </div>
        <div>
          <div class="d-flex align-items-center gap-2 my-3">
            <hr class="flex-grow-1 my-0" />
            <span class="small text-body-secondary">or send it to</span>
            <hr class="flex-grow-1 my-0" />
          </div>
          <div class="d-flex justify-content-center gap-2">
            <span class="btn position-relative btn-outline-secondary">
              <span><i class="bi bi-chat-dots" aria-hidden="true" /></span>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
