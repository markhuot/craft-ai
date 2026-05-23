import type { AiStarBootstrap, FillFieldRequest, FillFieldResponse } from "./types";

/**
 * Thin fetch wrapper around the AiStarController endpoints. Bundles CSRF
 * + JSON headers so the calling code can read like normal async/await
 * against the typed response shapes.
 */
export class AiStarApi {
  constructor(private readonly bootstrap: AiStarBootstrap) {}

  async fillField(request: FillFieldRequest): Promise<FillFieldResponse> {
    const body = new FormData();
    body.set("elementId", String(request.elementId));
    body.set("isDraft", request.isDraft ? "1" : "0");
    body.set("fieldHandle", request.fieldHandle);
    if (request.fieldLabel) body.set("fieldLabel", request.fieldLabel);
    if (typeof request.blockElementId === "number") {
      body.set("blockElementId", String(request.blockElementId));
    }
    if (request.blockTypeHandle) {
      body.set("blockTypeHandle", request.blockTypeHandle);
    }
    if (typeof request.siteId === "number") {
      body.set("siteId", String(request.siteId));
    }
    body.set(this.bootstrap.csrfTokenName, this.bootstrap.csrfTokenValue);

    const res = await fetch(this.bootstrap.fillFieldUrl, {
      method: "POST",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      body,
    });
    if (!res.ok) {
      throw new Error(`ai-star.fillField failed: ${res.status}`);
    }
    return (await res.json()) as FillFieldResponse;
  }
}
