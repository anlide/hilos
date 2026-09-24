<!-- HilosQrCode — a QR code drawn as SVG from the matrix the core computes
(`qrMatrix`, HIL-494). The core owns the library; this owns nothing but the
drawing: one path of dark squares over a light field, with the quiet zone of four
modules a scanner needs around it. Light and dark are fixed rather than themed —
a code inverted by a dark theme is a code most scanners refuse. -->
<script setup lang="ts">
import { qrMatrix } from '@hilos/core'
import { computed } from 'vue'

const props = defineProps<{
  /** The text the code carries, e.g. an `otpauth://` address. */
  text: string
  /** What the code is, for a reader who cannot see it. */
  label: string
}>()

/** Modules of light border every side needs for a scanner to find the code. */
const QUIET_ZONE = 4

const matrix = computed(() => qrMatrix(props.text))

const size = computed(() => matrix.value.length + 2 * QUIET_ZONE)

/** Every dark module as one unit square of a single path. */
const path = computed(() => {
  const squares: string[] = []
  matrix.value.forEach((row, y) => {
    row.forEach((dark, x) => {
      if (dark) {
        squares.push(`M${x + QUIET_ZONE} ${y + QUIET_ZONE}h1v1h-1z`)
      }
    })
  })

  return squares.join('')
})
</script>

<template>
  <svg
    :viewBox="`0 0 ${size} ${size}`"
    role="img"
    :aria-label="label"
    shape-rendering="crispEdges"
    class="d-block mx-auto"
    width="176"
    height="176"
    data-id="qr-code"
  >
    <rect :width="size" :height="size" fill="#fff" />
    <path :d="path" fill="#000" />
  </svg>
</template>
