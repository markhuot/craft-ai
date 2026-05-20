/*
 * Ambient module declaration for the `ckeditor5` bare specifier.
 *
 * The actual module is supplied at runtime by the host page's import
 * map (registered by craft\ckeditor\Plugin::init via registerJsImport).
 * Bun is configured to leave the specifier external at build time so
 * the literal `import` survives into our emitted module.
 *
 * Lives in its own `.d.ts` (and NOT inside index.ts) because once
 * index.ts has any `import` statement it becomes a real module, and a
 * `declare module "ckeditor5"` block inside it would be interpreted as
 * a *module augmentation* — which requires the module to already exist
 * in the type system. A standalone declaration file remains a top-
 * level ambient declaration and creates the module from scratch, which
 * is what we want here.
 */
declare module "ckeditor5" {
  // We deliberately stay loose — the plugin code uses `as any` /
  // `(x as any)` casts at every meaningful interaction with these
  // classes, and trying to ship real types for a moving target like
  // CKEditor 5 isn't worth the dev-dep cost.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  export const Command: any;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  export const Plugin: any;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  export const ButtonView: any;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  export const ClickObserver: any;
}
