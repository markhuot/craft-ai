#!/usr/bin/env bun
import { $ } from "bun";
import { watch } from "node:fs";
import { resolve } from "node:path";

const root = import.meta.dir;
const entry = resolve(root, "resources/chat/index.tsx");
const cssEntry = resolve(root, "resources/chat/styles.css");
const outdir = resolve(root, "src/web/assets/chat/dist");
const cssOut = resolve(outdir, "chat.css");

async function buildJs() {
  const start = performance.now();
  const result = await Bun.build({
    entrypoints: [entry],
    outdir,
    naming: "chat.js",
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
    console.error("JS build failed:");
    for (const m of result.logs) console.error(m);
    process.exitCode = 1;
    return;
  }
  console.log(`✅ chat.js built in ${ms}ms`);
}

async function buildCss() {
  const start = performance.now();
  await $`bunx @tailwindcss/cli -i ${cssEntry} -o ${cssOut} --minify`.quiet();
  const ms = (performance.now() - start).toFixed(0);
  console.log(`✅ chat.css built in ${ms}ms`);
}

async function buildAll() {
  await Promise.all([buildJs(), buildCss()]);
}

await buildAll();

if (process.argv.includes("--watch")) {
  console.log("👀 watching resources/chat for changes…");
  let pending = false;
  watch(resolve(root, "resources/chat"), { recursive: true }, () => {
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
