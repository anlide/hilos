# Frontend Vue Style

Read this before editing Vue single-file components in `framework/frontend`
or `demo/chat/frontend`.

## Global components

`vite-ssg` registers the SSR-only wrapper as the global Vue component
`ClientOnly`. Use PascalCase in templates:

```vue
<ClientOnly>
  ...
</ClientOnly>
```

Do not write it as `<client-only>`. Vue can resolve the kebab-case form at
runtime, but PhpStorm may inspect it as an unknown HTML tag. PascalCase matches
the global component declaration in `demo/chat/frontend/src/components.d.ts`
and keeps IDE template inspection aligned with the actual app setup.

## Line endings

Repository text files use LF line endings. `.gitattributes` enforces this with
`* text=auto eol=lf`.

Git warnings such as `CRLF will be replaced by LF` mean the working tree copy
contains CRLF, and Git will normalize the file to LF when it is indexed. Do not
try to preserve CRLF to silence the warning; keep frontend source files as LF.
