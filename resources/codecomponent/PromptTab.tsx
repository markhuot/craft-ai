import { useCallback, useMemo, useState } from "react";
import { Chat } from "../chat/Chat";
import type { ChatBootstrap } from "../chat/types";
import type { ChatUrls, ElementSummary, FieldValues } from "./types";

interface PromptTabProps {
  chatUrls: ChatUrls;
  fieldHandle: string;
  fieldName: string;
  fieldId: number;
  element: ElementSummary | null;
  values: FieldValues;
  agentSessionId: string | null;
  /** Called once a fresh session UUID is minted so the parent persists it
   * into the field's hidden input alongside the tab values. */
  onSessionMinted: (sessionId: string) => void;
  csrfTokenName: string;
  csrfTokenValue: string;
}

/**
 * Embeds the same `<Chat>` component the CP chat surface uses, with a
 * context payload describing the field the agent is helping the user
 * author. Sessions are lazy — the first call to `newSessionUrl` happens
 * the moment the user clicks "Start chatting", so a field that's never
 * been touched never spawns an empty session row.
 */
export function PromptTab({
  chatUrls,
  fieldHandle,
  fieldName,
  fieldId,
  element,
  values,
  agentSessionId,
  onSessionMinted,
  csrfTokenName,
  csrfTokenValue,
}: PromptTabProps) {
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const startSession = useCallback(async () => {
    setCreating(true);
    setError(null);
    try {
      const form = new FormData();
      form.set(csrfTokenName, csrfTokenValue);
      const res = await fetch(chatUrls.newSessionUrl, {
        method: "POST",
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        body: form,
      });
      if (!res.ok) {
        throw new Error(`Could not start a session (HTTP ${res.status}).`);
      }
      const data = (await res.json()) as { sessionId?: string };
      if (typeof data.sessionId !== "string" || data.sessionId === "") {
        throw new Error("Session create response was missing a sessionId.");
      }
      onSessionMinted(data.sessionId);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not start a chat session.");
    } finally {
      setCreating(false);
    }
  }, [chatUrls.newSessionUrl, csrfTokenName, csrfTokenValue, onSessionMinted]);

  const bootstrap = useMemo<ChatBootstrap | null>(() => {
    if (!agentSessionId) return null;

    // Context payload mirrors the shape `PageContextSerializer` already
    // understands so we don't have to special-case the backend. The
    // `element` block stays minimal; the field-author details ride along
    // as additional keys (`fieldHandle`, `fieldName`, `currentValues`) the
    // serializer ignores today but the agent can lean on through the JSON
    // prelude. A future serializer upgrade can format this more nicely.
    const context = {
      url: null,
      path: null,
      template: null,
      siteHandle: null,
      query: {},
      element: element
        ? {
            type: element.type,
            id: element.id,
            title: element.title,
            sectionHandle: element.sectionHandle,
          }
        : null,
      fieldAuthor: {
        kind: "code-component-field",
        fieldHandle,
        fieldName,
        fieldId,
        currentValues: {
          twig: values.twig,
          css: values.css,
          js: values.js,
        },
      },
    };

    return {
      sessionId: agentSessionId,
      messagesUrl: chatUrls.messagesUrl,
      sendUrl: chatUrls.sendUrl,
      sessionsUrl: chatUrls.sessionsUrl,
      newSessionUrl: chatUrls.newSessionUrl,
      sessionsIndexUrl: chatUrls.sessionsIndexUrl,
      assetsInfoUrl: "",
      previewRespondUrl: chatUrls.previewRespondUrl,
      toolModeUrl: chatUrls.toolModeUrl,
      updateToolModeUrl: chatUrls.updateToolModeUrl,
      csrfTokenName,
      csrfTokenValue,
      initialMessages: [],
      initialSessions: [],
      context,
      contextFingerprint: undefined,
      contextWindow: null,
    } satisfies ChatBootstrap;
  }, [agentSessionId, chatUrls, csrfTokenName, csrfTokenValue, element, fieldHandle, fieldName, fieldId, values.twig, values.css, values.js]);

  if (!bootstrap) {
    return (
      <div className="ai:flex ai:flex-col ai:items-start ai:gap-3 ai:p-4">
        <p className="ai:m-0 ai:text-sm ai:text-craftai-muted">
          Start a conversation with the agent about this component. Anything you author together
          gets written straight back into the Twig / CSS / JS tabs.
        </p>
        <button
          type="button"
          onClick={startSession}
          disabled={creating}
          className="ai:inline-flex ai:items-center ai:gap-1.5 ai:rounded-md ai:bg-slate-900 ai:px-3 ai:py-1.5 ai:text-xs ai:font-medium ai:text-white hover:ai:bg-slate-700 ai:disabled:opacity-50"
        >
          {creating ? "Starting…" : "Start chatting"}
        </button>
        {error && (
          <p role="alert" className="ai:m-0 ai:text-xs ai:text-red-700">
            {error}
          </p>
        )}
      </div>
    );
  }

  return (
    <div className="craftai-prompt-shell" data-testid="prompt-shell">
      <Chat bootstrap={bootstrap} />
    </div>
  );
}
