import { afterEach, describe, expect, test } from "bun:test";
import {
  findAllFields,
  readBlockElementId,
  readBlockTypeHandle,
  readElementContext,
} from "../dom";

afterEach(() => {
  document.body.innerHTML = "";
});

describe("readElementContext", () => {
  test("prefers draftId when both are present", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="elementId" value="100">
        <input type="hidden" name="draftId" value="42">
      </form>
    `;
    expect(readElementContext()).toEqual({ elementId: 42, isDraft: true, siteId: null });
  });

  test("falls back to elementId when no draftId is present", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="elementId" value="100">
      </form>
    `;
    expect(readElementContext()).toEqual({ elementId: 100, isDraft: false, siteId: null });
  });

  test("returns null when neither input is present", () => {
    document.body.innerHTML = `<form></form>`;
    expect(readElementContext()).toBeNull();
  });

  test("ignores draftId of zero", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="draftId" value="0">
        <input type="hidden" name="elementId" value="55">
      </form>
    `;
    expect(readElementContext()).toEqual({ elementId: 55, isDraft: false, siteId: null });
  });

  test("captures siteId when the form exposes it", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="elementId" value="42">
        <input type="hidden" name="siteId" value="2">
      </form>
    `;
    expect(readElementContext()).toEqual({ elementId: 42, isDraft: false, siteId: 2 });
  });

  test("siteId rides along with draft context too", () => {
    document.body.innerHTML = `
      <form>
        <input type="hidden" name="draftId" value="7">
        <input type="hidden" name="elementId" value="42">
        <input type="hidden" name="siteId" value="3">
      </form>
    `;
    expect(readElementContext()).toEqual({ elementId: 7, isDraft: true, siteId: 3 });
  });
});

describe("findAllFields", () => {
  test("discovers fields by data-attribute and id pattern", () => {
    document.body.innerHTML = `
      <form>
        <div class="field" id="fields-summary-field" data-attribute="summary">
          <div class="heading"><label>Summary</label></div>
        </div>
        <div class="field" id="fields-body-field">
          <div class="heading"><label>Body</label></div>
        </div>
      </form>
    `;
    const found = findAllFields();
    expect(found.map((f) => f.handle).sort()).toEqual(["body", "summary"]);
    const summary = found.find((f) => f.handle === "summary");
    expect(summary?.label).toBe("Summary");
  });

  test("ignores built-in title / status / etc. wrappers without the fields- segment", () => {
    document.body.innerHTML = `
      <form>
        <div class="field" id="title-field">
          <div class="heading"><label>Title</label></div>
        </div>
        <div class="field" id="fields-summary-field" data-attribute="summary">
          <div class="heading"><label>Summary</label></div>
        </div>
      </form>
    `;
    const found = findAllFields();
    expect(found.map((f) => f.handle)).toEqual(["summary"]);
  });

  test("scopes to #content-container, skipping #details-container fields", () => {
    document.body.innerHTML = `
      <form>
        <div id="content-container">
          <div class="field" id="fields-summary-field" data-attribute="summary">
            <div class="heading"><label>Summary</label></div>
          </div>
        </div>
        <div id="details-container">
          <div class="field" id="fields-postDate-field" data-attribute="postDate">
            <div class="heading"><label>Post Date</label></div>
          </div>
          <div class="field" id="fields-author-field" data-attribute="author">
            <div class="heading"><label>Author</label></div>
          </div>
        </div>
      </form>
    `;
    const found = findAllFields();
    expect(found.map((f) => f.handle)).toEqual(["summary"]);
  });

  test("excludes #details-container fields even without a #content-container", () => {
    document.body.innerHTML = `
      <form>
        <div class="field" id="fields-summary-field" data-attribute="summary">
          <div class="heading"><label>Summary</label></div>
        </div>
        <div id="details-container">
          <div class="field" id="fields-author-field" data-attribute="author">
            <div class="heading"><label>Author</label></div>
          </div>
        </div>
      </form>
    `;
    const found = findAllFields();
    expect(found.map((f) => f.handle)).toEqual(["summary"]);
  });

  test("detects the enclosing matrix block for nested fields", () => {
    document.body.innerHTML = `
      <form>
        <div class="matrixblock" data-id="999" data-type-handle="callout">
          <div class="field" id="block-x-fields-innerText-field" data-attribute="innerText">
            <div class="heading"><label>Inner Text</label></div>
          </div>
        </div>
      </form>
    `;
    const found = findAllFields();
    expect(found).toHaveLength(1);
    const f = found[0]!;
    expect(f.handle).toBe("innerText");
    expect(f.blockContainer).not.toBeNull();
    expect(readBlockElementId(f.blockContainer!)).toBe(999);
    expect(readBlockTypeHandle(f.blockContainer!)).toBe("callout");
  });
});
