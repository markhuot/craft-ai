import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { Chat } from "./Chat";
import type { ChatBootstrap, ChatMessage } from "./types";

declare global {
  interface Window {
    Craft?: {
      csrfTokenName?: string;
      csrfTokenValue?: string;
    };
  }
}

function readBootstrap(root: HTMLElement): ChatBootstrap {
  const dataEl = root.querySelector<HTMLScriptElement>("script[data-craftai-bootstrap]");
  if (!dataEl?.textContent) {
    throw new Error("craft-ai: missing bootstrap data");
  }
  const parsed: unknown = JSON.parse(dataEl.textContent);
  if (typeof parsed !== "object" || parsed === null) {
    throw new Error("craft-ai: invalid bootstrap data");
  }
  const obj = parsed as Record<string, unknown>;

  const initial = Array.isArray(obj.initialMessages) ? (obj.initialMessages as ChatMessage[]) : [];

  return {
    sessionId: String(obj.sessionId ?? ""),
    messagesUrl: String(obj.messagesUrl ?? ""),
    sendUrl: String(obj.sendUrl ?? ""),
    csrfTokenName: String(obj.csrfTokenName ?? window.Craft?.csrfTokenName ?? "CRAFT_CSRF_TOKEN"),
    csrfTokenValue: String(obj.csrfTokenValue ?? window.Craft?.csrfTokenValue ?? ""),
    initialMessages: initial,
  };
}

function mount() {
  const roots = document.querySelectorAll<HTMLElement>("[data-craftai-chat-root]");
  roots.forEach((el) => {
    if (el.dataset.craftaiMounted === "1") return;
    el.dataset.craftaiMounted = "1";
    const bootstrap = readBootstrap(el);
    createRoot(el).render(
      <StrictMode>
        <Chat bootstrap={bootstrap} />
      </StrictMode>,
    );
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mount, { once: true });
} else {
  mount();
}
