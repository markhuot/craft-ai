import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { SlashCommandMenu, filterCommands } from "../components/slash-command-menu";
import type { SlashCommand } from "../types";

afterEach(() => cleanup());

const commands: SlashCommand[] = [
  { name: "compact", description: "Summarize the conversation.", takesArgs: false },
  { name: "config", description: "Show current configuration.", takesArgs: false },
  { name: "rename", description: "Rename this session.", takesArgs: true },
];

describe("filterCommands", () => {
  test("returns nothing when the draft does not start with /", () => {
    expect(filterCommands("hello", commands)).toEqual([]);
  });

  test("returns the full catalog for a bare /", () => {
    expect(filterCommands("/", commands).map((c) => c.name)).toEqual([
      "compact",
      "config",
      "rename",
    ]);
  });

  test("filters by case-insensitive prefix of the command name", () => {
    expect(filterCommands("/CO", commands).map((c) => c.name)).toEqual([
      "compact",
      "config",
    ]);
    expect(filterCommands("/com", commands).map((c) => c.name)).toEqual([
      "compact",
    ]);
  });

  test("hides the menu once the user types a space (committed to a command)", () => {
    // After "/rename ", the user is typing args — the menu would obscure
    // their input, so we collapse it.
    expect(filterCommands("/rename ", commands)).toEqual([]);
    expect(filterCommands("/rename foo", commands)).toEqual([]);
  });

  test("returns empty when nothing matches the prefix", () => {
    expect(filterCommands("/zzz", commands)).toEqual([]);
  });
});

describe("<SlashCommandMenu />", () => {
  test("renders nothing when no commands match", () => {
    const { container } = render(
      <SlashCommandMenu
        draft="hello"
        commands={commands}
        selectedIndex={0}
        onPick={() => {}}
      />,
    );
    expect(container.firstChild).toBeNull();
  });

  test("renders filtered commands with the selected one flagged", () => {
    render(
      <SlashCommandMenu
        draft="/co"
        commands={commands}
        selectedIndex={1}
        onPick={() => {}}
      />,
    );
    const items = screen.getAllByRole("option");
    expect(items.length).toBe(2);
    expect(items[0]!.getAttribute("data-selected")).toBe("false");
    expect(items[1]!.getAttribute("data-selected")).toBe("true");
  });

  test("invokes onPick when a menu entry is clicked", () => {
    const picks: string[] = [];
    render(
      <SlashCommandMenu
        draft="/"
        commands={commands}
        selectedIndex={0}
        onPick={(c) => {
          picks.push(c.name);
        }}
      />,
    );
    const entry = screen.getByTestId("slash-command-item-rename");
    fireEvent.mouseDown(entry);
    expect(picks).toEqual(["rename"]);
  });
});
