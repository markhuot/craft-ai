/*
 * CKEditor 5 plugin: "Comment on selection."
 *
 * Loaded by craftcms/ckeditor (schemaVersion 5.0+) through its ES6
 * import map. craft\ckeditor\Plugin::init() registers our namespace
 * (`@markhuot/craft-ai-comment`) → this bundle URL, then the main
 * CKEditor bootstrap emits `import { CraftAiComment } from
 * "@markhuot/craft-ai-comment";` so we just need to be a real ES
 * module that re-exports the plugin classes.
 *
 * We import the framework primitives from `ckeditor5`. That bare
 * specifier is mapped by Craft's import map to the bundled CKEditor 5
 * core URL at runtime — we don't bundle our own copy, and at build
 * time the bundler treats `ckeditor5` as external so the import stays
 * literal in the output.
 *
 * What this contributes:
 *  - A text-level model attribute `craftAiComment` whose value is the
 *    comment record's `referenceId` (UUID-shaped string).
 *  - Downcast → `<span class="craft-ai-comment-mark"
 *    data-craft-ai-comment-id="$referenceId">` so the saved HTML
 *    carries the marker and the CP overlay can pin its indicator to
 *    the exact span when the entry is reopened.
 *  - Upcast for the same shape so an existing marker survives the
 *    editor's load-time data pipeline (data → view → model).
 *  - A `craftAiComment` toolbar button that prompts for the comment
 *    text, POSTs to the craft-ai endpoint, and stamps the returned
 *    referenceId onto the current selection. After success it
 *    dispatches a document-level event the overlay listens for so the
 *    indicator appears without a page reload.
 *
 * Sites enable the button by adding `craftAiComment` to a CKEditor
 * field's toolbar in Settings → CKEditor → Edit Config — matching how
 * craftcms/ckeditor expects third-party packages to opt in per config.
 */

// `ckeditor5` resolves through the host page's import map at runtime
// (declared by craft\ckeditor\Plugin::init via registerJsImport). At
// build time bun is told to leave the specifier external so the literal
// `import` survives into the emitted module. We don't ship typings for
// the framework here — `any` via the sibling `ckeditor5.d.ts` keeps
// the surface honest without dragging in @ckeditor/ckeditor5-* dev
// dependencies just for compile-time hints.
import { ButtonView, ClickObserver, Command, Plugin } from "ckeditor5";

interface ElementContext {
  elementId: number;
  isDraft: boolean;
}

/**
 * Walk up from the editor's source element to the surrounding entry
 * form, then read `draftId` / `elementId` / `sourceId` the same way the
 * comments overlay does. We scope to the closest form ancestor instead
 * of `document.querySelector` so a page with multiple forms still picks
 * up the right element id.
 */
function readElementContext(rootEl: HTMLElement): ElementContext | null {
  const form = rootEl.closest("form");
  const scope: ParentNode = form ?? document;

  const draftInput = scope.querySelector<HTMLInputElement>(
    'input[name="draftId"]',
  );
  if (draftInput && draftInput.value && draftInput.value !== "0") {
    const id = Number.parseInt(draftInput.value, 10);
    if (Number.isFinite(id) && id > 0) {
      return { elementId: id, isDraft: true };
    }
  }

  const elementInput = scope.querySelector<HTMLInputElement>(
    'input[name="elementId"], input[name="sourceId"]',
  );
  if (elementInput && elementInput.value) {
    const id = Number.parseInt(elementInput.value, 10);
    if (Number.isFinite(id) && id > 0) {
      return { elementId: id, isDraft: false };
    }
  }

  return null;
}

/**
 * Read the CKEditor field's handle off the surrounding markup.
 *
 * Craft 5 doesn't expose the handle as a single canonical attribute —
 * different parts of the CP use different conventions. We try, in
 * order:
 *
 *  1. `[data-handle]` / `[data-field-handle]` on an ancestor. Some
 *     custom field renderers (and a few stock ones) add it; cheapest
 *     check so it runs first.
 *  2. The closest field wrapper's id. Craft's namespaced field
 *     renderer stamps `id="…-fields-{handle}-field"` on every custom
 *     field container, where the prefix is empty at top level and
 *     contains the matrix namespace for nested blocks. The regex
 *     captures the handle without us having to reconstruct the
 *     namespace.
 *  3. The source element's `name` attribute. CKEditor's `sourceElement`
 *     is the original textarea, whose name is `fields[handle]` at top
 *     level and `…[fields][handle]` inside a matrix. The trailing
 *     `[handle]` is always the field handle.
 *
 * Returns null only when none of those land, which should be vanishingly
 * rare in practice — the alert that surfaces from a null return is
 * therefore wired to a generic "couldn't detect the field" message
 * rather than blaming save state.
 */
function readFieldHandle(rootEl: HTMLElement): string | null {
  const dataContainer = rootEl.closest<HTMLElement>(
    "[data-handle], [data-field-handle]",
  );
  if (dataContainer) {
    const handle =
      dataContainer.getAttribute("data-handle") ??
      dataContainer.getAttribute("data-field-handle");
    if (handle) return handle;
  }

  let current: HTMLElement | null = rootEl;
  while (current) {
    const id = current.id;
    if (id) {
      const match = id.match(/(?:^|-)fields-([A-Za-z0-9_]+)-field$/);
      if (match && match[1]) return match[1];
    }
    current = current.parentElement;
  }

  // Final fallback: parse the field handle off the textarea's `name`.
  // We accept either `name` on `rootEl` directly (when rootEl IS the
  // textarea) or the first descendant with a name attribute (when
  // rootEl is the editor's UI element wrapping the textarea).
  const directName = rootEl.getAttribute("name");
  const descendantName =
    directName ?? rootEl.querySelector("[name]")?.getAttribute("name") ?? null;
  if (descendantName) {
    const match = descendantName.match(/\[([A-Za-z0-9_]+)\]\s*$/);
    if (match && match[1]) return match[1];
  }

  return null;
}

/**
 * Toolbar icon. CKEditor 5's `ButtonView` takes a raw SVG string for
 * its `icon` property and stamps it into a `<svg class="ck-icon">`
 * wrapper, so any 20×20 path that uses `fill="currentColor"` inherits
 * the toolbar's normal/hover/active colors automatically.
 *
 * The shape is a speech bubble with three dots — a deliberately
 * unfussy "comment" mark that doesn't read as "chat" or "annotation"
 * specifically. Lifted from the public Heroicons "chat-bubble-oval"
 * outline; redrawn here so we don't have to pin a dependency.
 */
const COMMENT_ICON_SVG = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
  <path fill-rule="evenodd" d="M10 2.5c-4.418 0-8 2.91-8 6.5 0 1.785.886 3.4 2.323 4.572-.117.764-.43 1.728-1.066 2.602a.5.5 0 0 0 .555.776c1.95-.488 3.198-1.27 3.92-1.864A9.71 9.71 0 0 0 10 15.5c4.418 0 8-2.91 8-6.5s-3.582-6.5-8-6.5Zm-3 7.25a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm3 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm3 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" clip-rule="evenodd"/>
</svg>`.trim();

function generateUuid(): string {
  // crypto.randomUUID has been baseline since 2022, but the CP has a
  // long tail of older browsers — fall back to a stamped-random combo
  // that still produces a string the server's `string(64)` column
  // accepts. The fallback is good enough as a content-addressed marker
  // (only needs to be unique within one entry's HTML).
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return (
    Date.now().toString(36) +
    "-" +
    Math.random().toString(36).slice(2, 10) +
    "-" +
    Math.random().toString(36).slice(2, 10)
  );
}

/**
 * Command that toggles the `craftAiComment` text attribute on the
 * current selection. We don't piggyback on `linkUI` or another
 * existing inline attribute because the attribute is a model-level
 * thing tied to a comment record — repurposing `link` would confuse
 * the user (it'd try to render link decorators) and break upgrades.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
class CraftAiCommentCommand extends (Command as any) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  refresh(): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const editor = (this as any).editor;
    const model = editor.model;
    const selection = model.document.selection;

    // Enable the command only when the selection is non-empty AND the
    // schema allows our text attribute at that anchor. Stops the
    // button from lighting up inside contexts that strip text
    // attributes (e.g. inside a code block).
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).value = selection.getAttribute("craftAiComment") ?? null;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).isEnabled =
      !selection.isCollapsed &&
      model.schema.checkAttributeInSelection(selection, "craftAiComment");
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  execute(options: { referenceId: string }): void {
    const { referenceId } = options;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const editor = (this as any).editor;
    const model = editor.model;
    const selection = model.document.selection;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    model.change((writer: any) => {
      const ranges = model.schema.getValidRanges(
        selection.getRanges(),
        "craftAiComment",
      );
      for (const range of ranges) {
        writer.setAttribute("craftAiComment", referenceId, range);
      }
    });
  }
}

/**
 * Schema + conversion: define `craftAiComment` as a text attribute
 * that maps to `<span data-craft-ai-comment-id="…" class="…">`. The
 * `class` is decorative-only (the overlay matches on the data-attr),
 * but having a class makes it trivial for site-level CSS to highlight
 * comment ranges to the editor while they work.
 *
 * We use `attributeToElement` for both upcast and downcast so a span
 * wrapping any inline content (text, links, images) preserves the
 * marker correctly — `elementToElement` would only handle the case
 * where the span wraps plain text.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
class CraftAiCommentEditing extends (Plugin as any) {
  static get pluginName(): string {
    return "CraftAiCommentEditing";
  }

  init(): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const editor = (this as any).editor;

    // Track every live editor with our plugin loaded so the document-
    // level `craftai:comment-resolved` listener can find and unwrap
    // matching markers in their models. Without this the highlight
    // would linger in the editing view until the editor reloads the
    // page, even though the saved HTML was already stripped server-
    // side by CommentMarkerCleanup.
    activeEditors.add(editor);

    // Register a ClickObserver on the editing view. CKEditor 5 doesn't
    // wire one up by default; without this `view.document.on('click')`
    // never fires inside the contenteditable. Adding it is idempotent
    // (the view tracks registered observers), so collisions with
    // first-party plugins that also add it are fine.
    editor.editing.view.addObserver(ClickObserver);

    editor.model.schema.extend("$text", { allowAttributes: "craftAiComment" });

    editor.conversion.for("downcast").attributeToElement({
      model: "craftAiComment",
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      view: (referenceId: string | null, { writer }: { writer: any }) => {
        if (!referenceId) return null;
        return writer.createAttributeElement(
          "span",
          {
            class: "craft-ai-comment-mark",
            "data-craft-ai-comment-id": referenceId,
          },
          { priority: 5 },
        );
      },
    });

    editor.conversion.for("upcast").elementToAttribute({
      view: {
        name: "span",
        attributes: { "data-craft-ai-comment-id": /.+/ },
      },
      model: {
        key: "craftAiComment",
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        value: (viewElement: any) =>
          viewElement.getAttribute("data-craft-ai-comment-id"),
      },
    });

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const command = new (CraftAiCommentCommand as any)(editor);
    editor.commands.add("craftAiComment", command);

    // Native CKEditor click handler. Wiring on `view.document` instead
    // of the DOM `document` gives us two things:
    //   1. The event fires reliably from inside the editing view —
    //      DOM-level listeners can be silently swallowed by CKEditor's
    //      MouseObserver / SelectionObserver before bubbling out.
    //   2. The target is a *view* element, which we can introspect
    //      via getAttribute without depending on whether the rendered
    //      DOM still has the data attribute attached.
    //
    // When the click lands on (or inside) a `craftAiComment` span we
    // compute the marker's DOM rect for popover anchoring and dispatch
    // `craftai:open-span-comment` so the overlay (which owns the
    // popover surface) can take over. Stopping the CKEditor event
    // here prevents the selection observer from racing us to place
    // the caret inside the marker before the popover renders.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).listenTo(
      editor.editing.view.document,
      "click",
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (evt: any, data: any) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        let view: any = data?.target ?? null;
        let depth = 0;
        // Walk up the view tree to the AttributeElement carrying our
        // marker attribute. Click target is often a text node's
        // parent (the span itself) but we walk anyway to handle
        // formatting nested inside (e.g. <span><strong>bold</strong></span>).
        while (
          view &&
          (typeof view.getAttribute !== "function" ||
            !view.getAttribute("data-craft-ai-comment-id"))
        ) {
          view = view.parent;
          depth++;
          if (depth > 10) break; // safety: don't walk forever
        }
        if (!view) {
          // Fallback: the user might be clicking inside text whose
          // *model* (not view) carries craftAiComment. Check the
          // current model selection / position for the attribute and
          // proceed from there.
          try {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const mselection = editor.model.document.selection;
            const refFromModel: string | null = mselection.hasAttribute(
              "craftAiComment",
            )
              ? mselection.getAttribute("craftAiComment")
              : null;
            if (refFromModel) {
              this.dispatchOpen(refFromModel, data?.domEvent ?? null);
              evt.stop();
              if (data?.preventDefault) data.preventDefault();
            }
          } catch {
            // Fallback is best-effort — the regular view-walk path
            // covers the overwhelming majority of clicks.
          }
          return;
        }

        const referenceId = view.getAttribute("data-craft-ai-comment-id");
        if (!referenceId) return;

        // Map the view element to its rendered DOM node so the
        // popover can anchor at the highlight's bounding box. The
        // DomConverter's mapping is the source-of-truth for "where
        // does this view live in the page" — falling back to the
        // original DOM event coordinates if the mapping ever fails.
        let rect: DOMRect | null = null;
        try {
          const dom = editor.editing.view.domConverter.mapViewToDom(view);
          if (dom && typeof dom.getBoundingClientRect === "function") {
            rect = dom.getBoundingClientRect();
          }
        } catch {
          rect = null;
        }
        this.dispatchOpen(referenceId, data?.domEvent ?? null, rect);

        // Stop CKEditor from continuing its own click handling (caret
        // placement, link auto-focus, etc.). Without this the popover
        // can flicker as the selection observer fights it for focus.
        evt.stop();
        if (data?.preventDefault) data.preventDefault();
      },
      // High priority so we run before any downstream CKEditor
      // plugin listener that might also be looking at clicks.
      { priority: "high" },
    );
  }

  /**
   * Build the rect + dispatch the `craftai:open-span-comment` event.
   * Extracted so both the view-walk and model-selection fallback
   * paths emit the same payload shape. Falls back to a 0×0 rect at
   * the original click coordinates when the view-to-DOM mapping
   * isn't available — the overlay's positioner clamps to viewport
   * either way, so a degenerate rect still produces a sane popover.
   */
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private dispatchOpen(
    referenceId: string,
    domEvent: MouseEvent | null,
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    rect: DOMRect | null = null,
  ): void {
    let resolvedRect = rect;
    if (!resolvedRect && domEvent) {
      const x = domEvent.clientX ?? 0;
      const y = domEvent.clientY ?? 0;
      resolvedRect = new DOMRect(x, y, 0, 0);
    }
    document.dispatchEvent(
      new CustomEvent("craftai:open-span-comment", {
        detail: {
          referenceId,
          rect: resolvedRect
            ? {
                top: resolvedRect.top,
                left: resolvedRect.left,
                right: resolvedRect.right,
                bottom: resolvedRect.bottom,
                width: resolvedRect.width,
                height: resolvedRect.height,
              }
            : null,
        },
      }),
    );
  }

  /**
   * CKEditor calls this when an editor instance is torn down. Pull
   * ourselves out of the active-editors registry so a subsequent
   * `craftai:comment-resolved` event doesn't try to walk a disposed
   * model.
   */
  destroy(): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    activeEditors.delete((this as any).editor);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const sup = (Plugin as any).prototype?.destroy;
    if (typeof sup === "function") sup.call(this);
  }
}

/**
 * UI plugin: registers the toolbar button + click handler. We resolve
 * the bootstrap / context / handle on click rather than at init time
 * because the editor can mount before the form's hidden inputs are
 * populated (Craft re-injects them after autosave races).
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
class CraftAiCommentUI extends (Plugin as any) {
  static get pluginName(): string {
    return "CraftAiCommentUI";
  }

  init(): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const editor = (this as any).editor;
    const t = editor.t.bind(editor);

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    editor.ui.componentFactory.add("craftAiComment", (locale: any) => {
      const button = new ButtonView(locale);
      const command = editor.commands.get("craftAiComment");

      button.set({
        label: t("Comment"),
        tooltip: true,
        icon: COMMENT_ICON_SVG,
        // `withText: false` is the default when an icon is supplied,
        // but stating it makes the intent obvious — the label still
        // appears in the tooltip and as the screen-reader name.
        withText: false,
      });

      button.bind("isEnabled").to(command);

      button.on("execute", () => {
        void this.handleClick();
      });

      return button;
    });
  }

  private handleClick(): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const editor = (this as any).editor;
    const selection = editor.model.document.selection;
    if (selection.isCollapsed) return;

    const sourceEl =
      (editor.sourceElement as HTMLElement | null) ??
      (editor.ui.view.element as HTMLElement | null);
    if (!sourceEl) {
      console.warn("[craft-ai] CKEditor comment plugin: no source element");
      return;
    }

    const ctx = readElementContext(sourceEl);
    if (!ctx) {
      window.alert(
        "Could not detect which entry this editor belongs to. Save the entry once before leaving a comment.",
      );
      return;
    }

    const fieldHandle = readFieldHandle(sourceEl);
    if (!fieldHandle) {
      // Should be unreachable in practice — every CKEditor field
      // render path the CP knows about exposes the handle somewhere
      // readFieldHandle looks. If we hit this, the field markup has
      // changed shape and the heuristic needs a new fallback.
      window.alert(
        "Could not detect the CKEditor field handle. Please file a craft-ai bug — the comment was not saved.",
      );
      return;
    }

    // Mint the referenceId here and hand it to the widget along with
    // the rest of the context. The widget POSTs to comments/create
    // with the same id, and we wait for a `craftai:comment-created`
    // event back from the widget to apply the span marker. Two reasons
    // we don't await the network call inline:
    //   1. The composer is multi-line + (eventually) supports file
    //      uploads, so authoring can take a while. Keeping the editor
    //      free during that time matters.
    //   2. Decoupling lets the widget be the canonical surface for
    //      this kind of "leave a long-form note" interaction across
    //      future call sites (e.g. an "annotate" button on assets).
    const referenceId = generateUuid();
    const selectionText = extractSelectionText(editor);

    // Pending wrap state — keyed by referenceId so concurrent comments
    // (rare, but possible if the user fires the toolbar twice) each
    // resolve to the right editor.
    pendingWraps.set(referenceId, editor);

    document.dispatchEvent(
      new CustomEvent<CommentDraftDetail>("craftai:start-comment", {
        detail: {
          elementId: ctx.elementId,
          isDraft: ctx.isDraft,
          fieldHandle,
          referenceId,
          selectionText,
        },
      }),
    );
  }
}

/**
 * Pull a human-readable preview of the current selection out of the
 * editor's model. We walk the selected ranges and concatenate the
 * `data` of each text node — enough for the composer to show "you're
 * commenting on …" without the surrounding HTML. CKEditor doesn't
 * expose a "selected text" property directly, so this is the canonical
 * way to do it.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function extractSelectionText(editor: any): string {
  try {
    const ranges = editor.model.document.selection.getRanges();
    let out = "";
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    for (const range of ranges as Iterable<any>) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      for (const item of range.getItems() as Iterable<any>) {
        if (typeof item.data === "string") {
          out += item.data;
        }
      }
    }
    return out;
  } catch {
    return "";
  }
}

interface CommentDraftDetail {
  elementId: number;
  isDraft: boolean;
  fieldHandle: string;
  referenceId: string;
  selectionText: string;
}

interface CommentCreatedDetail {
  commentId: number;
  referenceId: string;
  elementId: number;
  isDraft: boolean;
  fieldHandle: string;
  sessionId: string;
}

/**
 * Per-page registry of editors waiting on their `craftai:start-comment`
 * round trip. Keyed by referenceId so a fast typist firing two comments
 * before the first one resolves still applies each marker to the
 * correct editor instance.
 *
 * Lives at module scope (not inside the UI plugin class) because the
 * listener that consumes it has to be document-wide — multiple
 * CKEditor fields on the same page would otherwise each spawn their
 * own listener and step on each other's events.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const pendingWraps = new Map<string, any>();

/**
 * Every editor on the page that has our `CraftAiCommentEditing` plugin
 * loaded. The `craftai:comment-resolved` listener iterates this to
 * strip the matching marker from each live editor's model the moment
 * a comment is resolved. Server-side cleanup (CommentMarkerCleanup)
 * still updates the saved HTML so a reload comes back clean, but
 * without this client-side strip the highlight would linger in the
 * editing view until the editor refreshes the page.
 *
 * Add on `init`, remove on `destroy` — a CKEditor instance that gets
 * torn down (e.g. on slide-out close) shouldn't keep receiving
 * resolve events against a disposed model.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const activeEditors = new Set<any>();

interface CommentResolvedDetail {
  commentId: number;
  referenceId: string;
}

/**
 * Walk a single editor's model looking for `craftAiComment` text
 * attributes matching `referenceId` and clear them. Removing the
 * attribute drops the downcast `<span>` wrapper on the next view sync,
 * so the yellow highlight disappears immediately. Ranges are collected
 * first and the mutation happens inside `model.change()` so we don't
 * invalidate the walker mid-iteration.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function removeMarkerInEditor(editor: any, referenceId: string): void {
  const model = editor.model;
  const root = model.document.getRoot();
  if (!root) return;

  const rootRange = model.createRangeIn(root);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const ranges: Array<{ start: any; end: any }> = [];
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  for (const step of rootRange.getWalker({ ignoreElementEnd: true }) as Iterable<any>) {
    if (step.type !== "text") continue;
    const value = step.item.getAttribute("craftAiComment");
    if (value === referenceId) {
      ranges.push({ start: step.previousPosition, end: step.nextPosition });
    }
  }
  if (ranges.length === 0) return;

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  model.change((writer: any) => {
    for (const r of ranges) {
      writer.removeAttribute(
        "craftAiComment",
        writer.createRange(r.start, r.end),
      );
    }
  });
}

if (typeof document !== "undefined") {
  document.addEventListener("craftai:comment-created", (e: Event) => {
    const detail = (e as CustomEvent<Partial<CommentCreatedDetail>>).detail;
    if (!detail || typeof detail.referenceId !== "string") return;
    const editor = pendingWraps.get(detail.referenceId);
    if (!editor) return;
    pendingWraps.delete(detail.referenceId);
    try {
      editor.execute("craftAiComment", { referenceId: detail.referenceId });
    } catch (err) {
      console.warn(
        "[craft-ai] CKEditor comment plugin: failed to apply marker",
        err,
      );
    }
  });

  // Resolving a comment fires CommentMarkerCleanup server-side, which
  // unwraps the marker from the entry's saved HTML so a reload comes
  // back clean. But the editor instance currently on screen still has
  // the attribute live in its model, so the yellow highlight would
  // linger until reload — visually contradicting the popover that
  // just disappeared. Mirror the server's strip across every active
  // editor so the highlight clears immediately. The server path
  // remains the source of truth for persistence; this is purely a
  // "match what the user just saw happen" view update.
  document.addEventListener("craftai:comment-resolved", (e: Event) => {
    const detail = (e as CustomEvent<Partial<CommentResolvedDetail>>).detail;
    if (!detail || typeof detail.referenceId !== "string") return;
    for (const editor of activeEditors) {
      try {
        removeMarkerInEditor(editor, detail.referenceId);
      } catch (err) {
        console.warn(
          "[craft-ai] CKEditor comment plugin: failed to clear resolved marker",
          err,
        );
      }
    }
  });
}

/**
 * Top-level plugin shell that craftcms/ckeditor's loader imports by
 * name. It just composes the editing + UI subplugins; the heavy lifting
 * lives in those classes. The static `pluginName` getter is what
 * craftcms/ckeditor looks up against the asset bundle's `pluginNames`
 * array to register us with the active CKEditor config.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export class CraftAiComment extends (Plugin as any) {
  static get pluginName(): string {
    return "CraftAiComment";
  }

  static get requires(): unknown[] {
    return [CraftAiCommentEditing, CraftAiCommentUI];
  }
}
