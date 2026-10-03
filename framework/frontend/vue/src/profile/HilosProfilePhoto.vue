<!-- The profile photo window: the core flow owns selection, crop and server outcome. -->
<script setup lang="ts">
import {
  drawHilosPhotoPreview,
  HILOS_PROFILE_PHOTO_COPY as COPY,
  type HilosProfilePhotoFlow,
} from '@hilos/core'
import { computed, nextTick, ref, watch } from 'vue'
import HilosAvatar from '../HilosAvatar.vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{ flow: HilosProfilePhotoFlow }>()
const step = useSignal(props.flow.step)
const photo = useSignal(props.flow.photo)
const name = useSignal(props.flow.name)
const initials = useSignal(props.flow.initials)
const preview = useSignal(props.flow.preview)
const zoom = useSignal(props.flow.zoom)
const busy = useSignal(props.flow.busy)
const checking = useSignal(props.flow.checking)
const refusal = useSignal(props.flow.refusal)
const voice = useSignal(props.flow.voice)
const input = ref<HTMLInputElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
let dragging: { id: number; x: number; y: number } | null = null
const open = computed({
  get: () => step.value !== 'closed',
  set: (value: boolean) => {
    if (!value) props.flow.close()
  },
})

watch([preview, step], () => {
  void nextTick(() => {
    if (canvas.value && preview.value) {
      drawHilosPhotoPreview(
        canvas.value,
        preview.value.bitmap,
        preview.value.square,
      )
    }
  })
})

function choose(): void {
  input.value?.click()
}
function picked(event: Event): void {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (file) void props.flow.pick(file)
  target.value = ''
}
function dropped(event: DragEvent): void {
  const file = event.dataTransfer?.files[0]
  if (file) void props.flow.pick(file)
}
function startDrag(event: PointerEvent): void {
  dragging = { id: event.pointerId, x: event.clientX, y: event.clientY }
  ;(event.currentTarget as HTMLCanvasElement).setPointerCapture(event.pointerId)
}
function drag(event: PointerEvent): void {
  if (!dragging || dragging.id !== event.pointerId) return
  const side = canvas.value?.getBoundingClientRect().width ?? 0
  if (side > 0)
    props.flow.move(
      event.clientX - dragging.x,
      event.clientY - dragging.y,
      side,
    )
  dragging = { id: event.pointerId, x: event.clientX, y: event.clientY }
}
function stopDrag(): void {
  dragging = null
}
function arrow(event: KeyboardEvent): void {
  const side = canvas.value?.getBoundingClientRect().width ?? 0
  if (side <= 0) return
  const motion = {
    ArrowLeft: [-8, 0],
    ArrowRight: [8, 0],
    ArrowUp: [0, -8],
    ArrowDown: [0, 8],
  }[event.key]
  if (!motion) return
  event.preventDefault()
  props.flow.move(motion[0], motion[1], side)
}
</script>

<template>
  <div
    class="visually-hidden"
    role="status"
    aria-live="polite"
    data-id="profile-photo-live-assertive"
  >
    {{ voice }}
  </div>
  <HilosModal v-model="open" :title="COPY.title" initial-focus="dialog">
    <input
      ref="input"
      type="file"
      class="visually-hidden"
      tabindex="-1"
      accept="image/jpeg,image/png,image/webp"
      data-id="profile-photo-input"
      @change="picked"
    />
    <div v-if="step === 'pick'" class="text-center">
      <div
        class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold hilos-avatar-lg"
      >
        {{ initials }}
      </div>
      <div class="small text-body-secondary mt-2 mb-3">
        {{ COPY.initialsNow }}
      </div>
      <div
        class="border border-2 hilos-photo-drop rounded py-4"
        data-id="profile-photo-drop"
        @dragover.prevent
        @drop.prevent="dropped"
      >
        <i
          class="bi bi-cloud-arrow-up text-body-secondary fs-4"
          aria-hidden="true"
        ></i>
        <div class="small mt-1">
          {{ COPY.dropLead }}
          <button
            type="button"
            class="btn btn-link btn-sm p-0 align-baseline"
            data-id="profile-photo-choose"
            @click="choose"
          >
            {{ COPY.choose }}
          </button>
        </div>
        <div class="form-text mb-0">{{ COPY.hint }}</div>
      </div>
    </div>
    <div v-else-if="step === 'crop'" class="text-center">
      <canvas
        ref="canvas"
        width="128"
        height="128"
        class="hilos-photo-crop rounded-circle mx-auto d-block touch-action-none"
        role="img"
        :aria-label="COPY.position"
        tabindex="0"
        data-id="profile-photo-preview"
        @pointerdown="startDrag"
        @pointermove="drag"
        @pointerup="stopDrag"
        @pointercancel="stopDrag"
        @keydown="arrow"
      ></canvas>
      <label
        class="form-label small fw-semibold d-block mt-3"
        for="profile-photo-zoom"
        >{{ COPY.zoom }}</label
      >
      <input
        id="profile-photo-zoom"
        type="range"
        class="form-range"
        min="1"
        max="4"
        step="0.01"
        :value="zoom"
        :disabled="busy || checking"
        data-id="profile-photo-zoom"
        @input="flow.setZoom(Number(($event.target as HTMLInputElement).value))"
      />
      <p class="small text-body-secondary mb-0">{{ COPY.cropNote }}</p>
      <p
        v-if="checking"
        class="small text-body-secondary mt-2"
        data-id="profile-photo-checking"
      >
        {{ COPY.checking }}
      </p>
    </div>
    <div v-else-if="step === 'current'" class="text-center">
      <HilosAvatar :name="name" :photo="photo" size="lg" />
      <div class="d-grid gap-2 mt-3">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="busy"
          data-id="profile-photo-upload-another"
          @click="choose"
        >
          {{ COPY.uploadAnother }}
        </button>
        <button
          type="button"
          class="btn btn-outline-danger btn-sm"
          :disabled="busy"
          data-id="profile-photo-remove"
          @click="flow.remove()"
        >
          {{ COPY.remove }}
        </button>
      </div>
      <p class="small text-body-secondary mt-2 mb-0">{{ COPY.removeNote }}</p>
    </div>
    <HilosFormError :message="refusal" data-id="profile-photo-error" />
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-outline-secondary"
        data-id="profile-photo-cancel"
        @click="requestClose"
      >
        {{ COPY.cancel }}
      </button>
      <LoadingButton
        v-if="step === 'crop'"
        class="btn-primary"
        :loading="busy"
        :disabled="checking"
        data-id="profile-photo-save"
        @click="flow.save()"
        >{{ COPY.save }}</LoadingButton
      >
    </template>
  </HilosModal>
</template>
