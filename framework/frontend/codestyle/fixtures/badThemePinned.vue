<!-- Deliberately broken: pinned classes, literal/SVG/canvas paint and a local theme. -->
<template>
  <div class="bg-light" />
  <span class="text-dark" />
  <span class="text-bg-light" />
  <button class="btn btn-black" />
  <button class="btn btn-outline-dark" />
  <div class="border-white" />
  <a class="link-light" />
  <table class="table-black" />
  <div class="dropdown-menu-dark" />
  <button class="btn-close-white" />
  <span class="text-black-50" />
  <svg><path fill="red" stroke="#abc" /></svg>
  <div data-bs-theme="light" />
</template>

<script setup lang="ts">
const accent = '#aabbcc'

export function paint(context: CanvasRenderingContext2D): void {
  context.fillStyle = accent
  context.strokeStyle = 'rgb(0 0 0)'
  context.shadowColor = '#000'
}
</script>
