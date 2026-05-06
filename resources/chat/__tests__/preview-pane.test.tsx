import { afterEach, describe, expect, test } from "bun:test";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { createRef } from "react";
import { PreviewPane, type PreviewPaneHandle } from "../components/preview-pane";

afterEach(() => cleanup());

function noop() {}

describe("<PreviewPane />", () => {
  test("renders the iframe with the supplied url", () => {
    render(
      <PreviewPane
        url="https://example.com"
        mode="peek"
        loading={false}
        onLoad={noop}
        onError={noop}
        onExpand={noop}
        onCollapse={noop}
        onClose={noop}
      />,
    );
    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    expect(iframe.src).toContain("https://example.com");
  });

  test("shows the loading badge while pending", () => {
    render(
      <PreviewPane
        url="/x"
        mode="peek"
        loading={true}
        onLoad={noop}
        onError={noop}
        onExpand={noop}
        onCollapse={noop}
        onClose={noop}
      />,
    );
    expect(screen.getByTestId("preview-loading")).toBeTruthy();
  });

  test("shows the expand control in peek mode and the shrink control when expanded", () => {
    const { rerender } = render(
      <PreviewPane
        url="/x"
        mode="peek"
        loading={false}
        onLoad={noop}
        onError={noop}
        onExpand={noop}
        onCollapse={noop}
        onClose={noop}
      />,
    );
    expect(screen.getByTestId("preview-expand")).toBeTruthy();
    expect(screen.queryByTestId("preview-shrink")).toBeNull();

    rerender(
      <PreviewPane
        url="/x"
        mode="expanded"
        loading={false}
        onLoad={noop}
        onError={noop}
        onExpand={noop}
        onCollapse={noop}
        onClose={noop}
      />,
    );
    expect(screen.queryByTestId("preview-expand")).toBeNull();
    expect(screen.getByTestId("preview-shrink")).toBeTruthy();
  });

  test("clicking expand/collapse/close fires the corresponding handler", () => {
    let calls: string[] = [];
    const { rerender } = render(
      <PreviewPane
        url="/x"
        mode="peek"
        loading={false}
        onLoad={noop}
        onError={noop}
        onExpand={() => calls.push("expand")}
        onCollapse={() => calls.push("collapse")}
        onClose={() => calls.push("close")}
      />,
    );

    fireEvent.click(screen.getByTestId("preview-expand"));
    fireEvent.click(screen.getByTestId("preview-close"));

    rerender(
      <PreviewPane
        url="/x"
        mode="expanded"
        loading={false}
        onLoad={noop}
        onError={noop}
        onExpand={() => calls.push("expand")}
        onCollapse={() => calls.push("collapse")}
        onClose={() => calls.push("close")}
      />,
    );

    fireEvent.click(screen.getByTestId("preview-shrink"));
    expect(calls).toEqual(["expand", "close", "collapse"]);
  });

  test("invokes onLoad once when the iframe load event fires", () => {
    let loaded = 0;
    const captured: { url: string | null } = { url: null };
    render(
      <PreviewPane
        url="https://example.com/foo"
        mode="peek"
        loading={true}
        onLoad={(url) => {
          loaded += 1;
          captured.url = url;
        }}
        onError={noop}
        onExpand={noop}
        onCollapse={noop}
        onClose={noop}
      />,
    );

    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    fireEvent.load(iframe);
    fireEvent.load(iframe); // duplicate event must not double-fire

    expect(loaded).toBe(1);
    expect(captured.url).toBe("https://example.com/foo");
  });

  test("readContents returns the iframe document text", () => {
    const ref = createRef<PreviewPaneHandle>();
    render(
      <PreviewPane
        ref={ref}
        url="about:blank"
        mode="peek"
        loading={false}
        onLoad={noop}
        onError={noop}
        onExpand={noop}
        onCollapse={noop}
        onClose={noop}
      />,
    );

    const iframe = screen.getByTestId("preview-iframe") as HTMLIFrameElement;
    // Drive jsdom into giving the iframe a usable document. about:blank
    // mounts to a writable Document we can populate ourselves.
    const doc = iframe.contentDocument;
    if (doc) {
      doc.open();
      doc.write(
        "<html><head></head><body><h1>Hello</h1><p>World &amp; friends</p></body></html>",
      );
      doc.close();
    }
    fireEvent.load(iframe);

    const text = ref.current!.readContents("text");
    expect(text).toContain("Hello");
    expect(text).toContain("World");

    const html = ref.current!.readContents("full");
    expect(html).toContain("<html");
    expect(html).toContain("<h1>Hello</h1>");
  });

});
