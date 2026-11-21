<template>
  <!-- Комментарий шаблона -->
  <p :title="label">Текст страницы</p>
  <p>{{ '<!-- Подпись -->' }}</p>
  <p>{{ '<!-- FIXME(F-1, 2026-11-20) Подпись -->' }}<!-- После выражения --></p>
  <svg>
    <title><!-- Название рисунка -->Рисунок</title>
    <text><![CDATA[<!-- Подпись -->]]></text>
    <!-- После данных SVG -->
  </svg>
  <textarea><!-- Подпись --></textarea><!-- После поля ввода -->
  <iframe>
    <section>
      <!-- Подпись -->
    </section>
  </iframe>
  <!-- После фрейма -->
</template>

<messages lang="json">
{
  "example": "<!-- Подпись -->",
  "code": "<script>// Подсказка</script>"
}
</messages>
<!-- После пользовательского блока -->

<script setup lang="ts&#120;">
const label = 'данные' // Комментарий скрипта
const example = '<!-- Подпись'
void (<p>// Данные JSX</p>
  + example)
// После текста JSX
</script>
<!-- Настоящий комментарий -->
