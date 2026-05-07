import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { PermissionMode } from "../components/permission-mode";
import type { AvailableTool, ToolMode } from "../types";

afterEach(() => cleanup());

const TOOLS: AvailableTool[] = [
  { name: "get_health", description: "Health", kind: "read" },
  { name: "upsert_draft", description: "Upsert draft", kind: "draftWrite" },
  { name: "delete_drafts", description: "Delete drafts", kind: "draftWrite" },
  { name: "upsert_entry", description: "Upsert entry", kind: "liveWrite" },
];

function setup(
  initialMode: ToolMode = "full",
  initialEnabled: string[] | null = null,
) {
  const calls: Array<{ mode: ToolMode; enabled: string[] | null }> = [];
  render(
    <PermissionMode
      mode={initialMode}
      enabledTools={initialEnabled}
      availableTools={TOOLS}
      onChange={(mode, enabled) => calls.push({ mode, enabled })}
    />,
  );
  return calls;
}

describe("<PermissionMode />", () => {
  test("button label reflects the current mode", () => {
    setup("draft");
    expect(screen.getByTestId("permission-mode-button").textContent).toContain("Draft");
  });

  test("opens the menu on click and shows the four mode options", () => {
    setup();
    fireEvent.click(screen.getByTestId("permission-mode-button"));
    expect(screen.getByTestId("permission-mode-menu")).toBeTruthy();
    expect(screen.getByTestId("permission-mode-option-full")).toBeTruthy();
    expect(screen.getByTestId("permission-mode-option-draft")).toBeTruthy();
    expect(screen.getByTestId("permission-mode-option-readonly")).toBeTruthy();
    expect(screen.getByTestId("permission-mode-option-custom")).toBeTruthy();
  });

  test("emits the new mode when a non-custom option is picked and clears enabledTools", () => {
    const calls = setup("full");
    fireEvent.click(screen.getByTestId("permission-mode-button"));
    fireEvent.click(screen.getByTestId("permission-mode-option-readonly"));
    expect(calls).toEqual([{ mode: "readonly", enabled: null }]);
  });

  test("seeds Custom mode with the previous mode's effective tool list when no allowlist is set", () => {
    const calls = setup("draft");
    fireEvent.click(screen.getByTestId("permission-mode-button"));
    fireEvent.click(screen.getByTestId("permission-mode-option-custom"));
    expect(calls).toEqual([
      {
        mode: "custom",
        enabled: ["get_health", "upsert_draft", "delete_drafts"],
      },
    ]);
  });

  test("renders checkboxes grouped by kind in custom mode", () => {
    setup("custom", ["get_health"]);
    fireEvent.click(screen.getByTestId("permission-mode-button"));
    expect(screen.getByTestId("permission-mode-custom-list")).toBeTruthy();
    expect(
      (screen.getByTestId("permission-mode-tool-get_health") as HTMLInputElement).checked,
    ).toBe(true);
    expect(
      (screen.getByTestId("permission-mode-tool-upsert_entry") as HTMLInputElement).checked,
    ).toBe(false);
  });

  test("toggling a checkbox emits a stable, registry-ordered allowlist", () => {
    const calls = setup("custom", ["get_health"]);
    fireEvent.click(screen.getByTestId("permission-mode-button"));
    fireEvent.click(screen.getByTestId("permission-mode-tool-upsert_entry"));
    expect(calls).toEqual([
      // Order matches availableTools, not the user's click sequence — that
      // keeps the persisted JSON stable across saves.
      { mode: "custom", enabled: ["get_health", "upsert_entry"] },
    ]);
  });

  test("unchecking a checkbox removes the tool from the allowlist", () => {
    const calls = setup("custom", ["get_health", "upsert_entry"]);
    fireEvent.click(screen.getByTestId("permission-mode-button"));
    fireEvent.click(screen.getByTestId("permission-mode-tool-get_health"));
    expect(calls).toEqual([{ mode: "custom", enabled: ["upsert_entry"] }]);
  });
});
