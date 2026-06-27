// Ambient shape for side-effect stylesheet imports: the Bootstrap stylesheet and
// the Hilos custom Sass layer the SDK ships (src/index.ts). The bundler handles
// the asset; TypeScript only needs to know the module resolves to nothing
// importable.
declare module '*.css'
declare module '*.scss'
