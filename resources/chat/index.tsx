import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { Shell } from "./Shell";
// Side-effect import: registers the Window.Craft ambient declaration once,
// which is the same global both this entry and the asset selector reach for.
import "./lib/assetSelector";
import type { ChatBootstrap, ChatMessage, SessionListItem } from "./types";

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
  const initialSessions = Array.isArray(obj.initialSessions)
    ? (obj.initialSessions as SessionListItem[])
    : [];

  return {
    sessionId: String(obj.sessionId ?? ""),
    messagesUrl: String(obj.messagesUrl ?? ""),
    sendUrl: String(obj.sendUrl ?? ""),
    sessionsUrl: String(obj.sessionsUrl ?? ""),
    newSessionUrl: String(obj.newSessionUrl ?? ""),
    sessionsIndexUrl: String(obj.sessionsIndexUrl ?? ""),
    assetsInfoUrl: String(obj.assetsInfoUrl ?? ""),
    previewRespondUrl: String(obj.previewRespondUrl ?? ""),
    csrfTokenName: String(obj.csrfTokenName ?? window.Craft?.csrfTokenName ?? "CRAFT_CSRF_TOKEN"),
    csrfTokenValue: String(obj.csrfTokenValue ?? window.Craft?.csrfTokenValue ?? ""),
    initialMessages: initial,
    initialSessions,
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
        <Shell bootstrap={bootstrap} />
      </StrictMode>,
    );
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mount, { once: true });
} else {
  mount();
}
