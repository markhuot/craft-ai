import { GlobalRegistrator } from "@happy-dom/global-registrator";

GlobalRegistrator.register({ url: "http://localhost/" });

// React 19's act() relies on this global flag being set.
// @ts-expect-error — runtime-only flag
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
