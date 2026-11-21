<script setup lang="ts">
import { ref } from 'vue'

const VIDEO_SRC = '/media/hilos.mp4'
const missing = ref(false)
</script>

<template>
  <section aria-labelledby="home-video-title">
    <h2 id="home-video-title">See Hilos in action</h2>
    <p>
      A silent walkthrough shows a change in one demo window appearing in
      another without a page reload.
    </p>
    <div class="ratio ratio-16x9">
      <video
        v-if="!missing"
        controls
        preload="metadata"
        playsinline
        :src="VIDEO_SRC"
        aria-labelledby="home-video-title"
        @error="missing = true"
      >
        Your browser does not support video playback.
        <a :href="VIDEO_SRC">Download the Hilos walkthrough</a>.
      </video>
      <div
        v-else
        class="d-flex align-items-center justify-content-center bg-body-tertiary border rounded p-3 text-center"
      >
        <p class="mb-0">
          The video is coming soon. Explore the demos below in the meantime.
        </p>
      </div>
    </div>
  </section>
</template>
