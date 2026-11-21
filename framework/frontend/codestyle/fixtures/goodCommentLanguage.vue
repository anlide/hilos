<template>
  <p title="Подсказка">Текст страницы {{ label }}</p>
  <p title="<script> // Подсказка </script>"></p>
  <p title="a > b <!-- Подпись -->"></p>
  <textarea><script>// Подсказка</script> <!-- Подпись --></textarea>
  <p>{{ '<!-- Подпись -->' }}</p>
  <svg><text><![CDATA[<!-- Подпись -->]]></text></svg>
</template>
<i18n lang="json">
{ "sample": "<!-- Данные -->", "code": "<script>// Подпись</script>" }
</i18n>
<!-- prettier-ignore -->
<script setup lang='jsx'>
const label = 'строка // данные'
const example = '<!-- Подпись -->'
void (<p>// Текст JSX</p>
  + example)
</script>
