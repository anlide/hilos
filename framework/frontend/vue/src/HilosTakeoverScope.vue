<!-- HilosTakeoverScope — the region of a takeover that only looks (HIL-1170).
It provides, to what it holds and to nothing else, whether this session is
inside a takeover where the administrator only looks (the core's
hilosTakeoverViewOnly, mirrored into Vue): the controls of the mode read it
through useLookOnly() and stand plainly disabled, described by the
impersonation strip's text. The application shell wraps the page's area in it
and never the shell itself, so the strip's Stop, "Keep my account" and signing
out stay live. It draws no element of its own. Internal to the Vue view layer:
the shell is its one mount. -->
<script setup lang="ts">
import { hilosTakeoverViewOnly } from '@hilos/core'
import { provide } from 'vue'

import { hilosTakeoverViewOnlyKey } from './hilosAdminViewMode.js'
import { useSignal } from './useSignal.js'

provide(hilosTakeoverViewOnlyKey, useSignal(hilosTakeoverViewOnly))
</script>

<template>
  <slot />
</template>
