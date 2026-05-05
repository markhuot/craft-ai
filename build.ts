#!/usr/bin/env bun
import { $ } from "bun";
import { watch } from "node:fs";
import { resolve } from "node:path";

const root = import.meta.dir;

interface Bundle {
  name: string;
  jsEntry: string;
  jsOut: string;
  cssEntry: string;
  cssOut: string;
}

const bundles: Bundle[] = [
  {
    name: "chat",
    jsEntry: resolve(root, "resources/chat/index.tsx"),
    jsOut: "chat.js",
    cssEntry: resolve(root, "resources/chat/styles.css"),
    cssOut: resolve(root, "src/web/assets/chat/dist/chat.css"),
  },
  {
    name: "widget",
    jsEntry: resolve(root, "resources/widget/index.tsx"),
    jsOut: "widget.js",
    cssEntry: resolve(root, "resources/widget/styles.css"),
    cssOut: resolve(root, "src/web/assets/widget/dist/widget.css"),
  },
];

async function buildJs(bundle: Bundle): Promise<void> {
  const start = performance.now();
  const result = await Bun.build({
    entrypoints: [bundle.jsEntry],
    outdir: resolve(root, `src/web/assets/${bundle.name}/dist`),
    naming: bundle.jsOut,
    target: "browser",
    format: "esm",
    minify: true,
    sourcemap: "linked",
    define: {
      "process.env.NODE_ENV": JSON.stringify("production"),
    },
  });
  const ms = (performance.now() - start).toFixed(0);
  if (!result.success) {
    console.error(`${bundle.name} JS build failed:`);
    for (const m of result.logs) console.error(m);
    process.exitCode = 1;
    return;
  }
  console.log(`✅ ${bundle.jsOut} built in ${ms}ms`);
}

async function buildCss(bundle: Bundle): Promise<void> {
  const start = performance.now();
  await $`bunx @tailwindcss/cli -i ${bundle.cssEntry} -o ${bundle.cssOut} --minify`.quiet();
  const ms = (performance.now() - start).toFixed(0);
  console.log(`✅ ${bundle.name}.css built in ${ms}ms`);
}

async function buildAll(): Promise<void> {
  await Promise.all(
    bundles.flatMap((b) => [buildJs(b), buildCss(b)]),
  );
}

await buildAll();

if (process.argv.includes("--watch")) {
  console.log("👀 watching resources/ for changes…");
  let pending = false;
  watch(resolve(root, "resources"), { recursive: true }, () => {
    if (pending) return;
    pending = true;
    setTimeout(async () => {
      pending = false;
      try {
        await buildAll();
      } catch (e) {
        console.error(e);
      }
    }, 50);
  });
  // Keep the process alive while watching.
  await new Promise<void>(() => {});
}
