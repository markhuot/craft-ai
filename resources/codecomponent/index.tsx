import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { Editor } from "./Editor";
import type { FieldBootstrap } from "./types";

declare global {
  interface Window {
    Craft?: {
      csrfTokenName?: string;
      csrfTokenValue?: string;
    };
  }
}

function readBootstrap(root: HTMLElement): FieldBootstrap | null {
  const dataEl = root.querySelector<HTMLScriptElement>(
    "script[data-craftai-code-component-bootstrap]",
  );
  if (!dataEl?.textContent) return null;
  try {
    const parsed = JSON.parse(dataEl.textContent) as FieldBootstrap;
    return parsed;
  } catch {
    return null;
  }
}

function collectHiddenInputs(root: HTMLElement) {
  return {
    twig: root.querySelector<HTMLInputElement>(
      'input[data-craftai-code-component-input="twig"]',
    ),
    css: root.querySelector<HTMLInputElement>(
      'input[data-craftai-code-component-input="css"]',
    ),
    js: root.querySelector<HTMLInputElement>(
      'input[data-craftai-code-component-input="js"]',
    ),
    agentSessionId: root.querySelector<HTMLInputElement>(
      'input[data-craftai-code-component-input="agentSessionId"]',
    ),
  };
}

function mount() {
  const roots = document.querySelectorAll<HTMLElement>(
    "[data-craftai-code-component-root]",
  );
  roots.forEach((el) => {
    if (el.dataset.craftaiMounted === "1") return;
    const bootstrap = readBootstrap(el);
    if (!bootstrap) return;
    const hiddenInputs = collectHiddenInputs(el);
    el.dataset.craftaiMounted = "1";

    const csrfTokenName = window.Craft?.csrfTokenName ?? "CRAFT_CSRF_TOKEN";
    const csrfTokenValue = window.Craft?.csrfTokenValue ?? "";

    // Render a sibling container so the bootstrap <script> + hidden
    // inputs (which Craft will serialize on save) keep their original
    // DOM positions inside the root. Otherwise React would unmount them
    // on first render and Craft's form save would lose the values.
    const mountPoint = document.createElement("div");
    el.appendChild(mountPoint);

    createRoot(mountPoint).render(
      <StrictMode>
        <Editor
          bootstrap={bootstrap}
          hiddenInputs={hiddenInputs}
          csrfTokenName={csrfTokenName}
          csrfTokenValue={csrfTokenValue}
        />
      </StrictMode>,
    );
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mount, { once: true });
} else {
  mount();
}

// Re-run on Craft's HTMX-flavored partial reloads. Craft's CP fires this
// event whenever it re-renders a region (e.g. the entry-edit drawer), and
// the field input HTML may appear inside one — without a re-mount the
// editor would never bind to the freshly inserted root.
document.addEventListener("htmx:afterSettle", () => mount());
