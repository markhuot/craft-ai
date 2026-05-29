# Craft AI — Speculative Roadmap

> ⚠️ **Not a commitment.** Nothing in this document is planned, scheduled, or
> promised. It's a brainstorm of innovative directions Craft AI *could* go
> beyond what already ships today. Treat it as a sketchbook, not a roadmap.

This is a parking lot of ideas that lean into Craft's specific shape (sections,
entry types, field layouts, drafts/canonical, Matrix blocks, project config,
sites/i18n) and into capabilities that are genuinely differentiated for an
agentic AI — not just "wrap a chatbot around a workflow."

---

## 1. Embeddings as plugin infrastructure

A built-in vector index over entries / assets / templates is the single
highest-leverage piece of plumbing left on the table. Once it exists, it
cheaply unlocks:

- **Semantic search** as both an agent tool and a `<craft-ai-search>` front-end
  web component.
- **Duplicate / near-duplicate detection** on draft save ("this is 78% similar
  to entry #1402 — merge or differentiate?").
- **Auto related-entries** that beats hand-curated fields, with a rationale.
- **Staleness / drift scoring** — combine embedding age, web-search
  verification, and revision history into a "needs review" badge with a
  reason.
- **Tag / category suggestion** from continuous clustering — propose *new*
  categories when a cluster emerges, not just classify into existing ones.

The wedge: every competing Craft AI plugin will eventually bolt on a chat.
None of them will have a year of embeddings + drift telemetry.

## 2. Schema inference from messy sources

Drop a CSV, competitor URL, brochure PDF, or Notion export → agent proposes
**section + entry type + field layout + Matrix block configs**, then imports
the rows as entries in a single guided session. The tooling already exists
(`create_section`, `create_entry_type`, `update_field_layout`); what's missing
is the inference loop and a preview-before-commit UX. This is the onboarding
wedge for new Craft sites.

## 3. Editorial policy engine (declarative guardrails)

Automations today are reactive. Flip it: let admins write policy in English
under **Settings → Guardrails** — "press releases need a legal-approved
citation," "no medical claims without source," "brand voice: confident but not
breathless" — and the agent enforces them on `draft.applied` with auto-comments
or a soft block.

Paired with the embeddings index, brand voice stops being a vibe: "your draft
sits 2.4σ outside the cluster of the last 200 published posts in this
section." Defensible, measurable, governance-y.

## 4. The "why did this go live?" timeline

Craft AI uniquely has the data: chat sessions, forked discussions, inline
comments, draft revisions, automations, MCP client calls, slash-command
invocations. Join them into a single per-entry timeline and let the agent
summarize it:

> *"This entry was drafted from a `/translate` run on May 3, reviewed in
> session #882 where Sarah accepted 4 of 6 suggestions, applied by you on May
> 8 — the price field disagrees with Stripe as of yesterday."*

Unsellable until it exists, then it's "how did we live without it."
Compliance teams will pay for it specifically.

## 5. Project-config drift sentinel + plain-English PR bot

Two flavors of the same idea:

- **Drift sentinel**: agent watches DB-applied project config vs YAML on disk
  and explains divergence ("someone added a `subtitle` field via the CP on
  staging that isn't in `project.yaml`").
- **PR commenter**: on any project-config YAML diff in a PR, agent posts a
  plain-English changelog ("This PR renames the `blog` section to `articles`
  and adds a required `readingTime` field — non-breaking for entries, breaking
  for templates referencing `.handle`"). Aimed at PMs who can't read YAML.

Pairs well with a **Craft N → N+1 migration linter** that flags deprecated
patterns in custom modules.

## 6. Multimodal in / out

Multimodal *in* already works (asset attachments). Multimodal *out* is the
next surface:

- **Video → entry pipeline**: upload webinar → draft blog post + chapter
  markers + social variants + transcript field.
- **Audio version of every article (TTS)**: one-click "publish audio" stores
  the MP3 as a Craft asset and binds it to the entry.
- **Voice-first mobile drafting**: voice memo + photos uploaded from a phone
  → field-reporter draft. The frontend widget already has the surface; this
  just needs a recorder + Whisper.

## 7. Multi-agent editorial calendar (speculative)

Persistent agent *roles* — Strategist, Writer, SEO, Legal — that hold their
own backlog and meet on a calendar. Each role has its own scoped tool set
(per-session scopes already exist in the plugin). The user watches threads
and intervenes when wanted.

The novelty isn't multi-agent itself; it's that Craft's data model
(sections, drafts, comments, sessions) is a near-perfect substrate to make
multi-agent collaboration legible to humans instead of a black box.

## 8. Closed-loop A/B & personalization

Agent proposes headline variants → wires them up via a new `experiment` entry
type → watches GA / Plausible → calls a winner → applies the winning draft.
The "calls a winner" step is what makes this genuinely agentic vs.
yet-another-experimentation tool.

Edge-rendered personalized hero variants (a Cloudflare worker hitting the LLM
with aggressive caching) is the same machine pointed sideways.

## 9. Twig LSP backed by the MCP server

The MCP server already knows every section / field / entry type. Wrap it in
an LSP and you get `entry.<TAB>` autocomplete with real handles in VS Code,
JetBrains, Zed. Developers will install Craft AI *just for this* and
discover the rest of the surface area afterward. Distribution wedge.

---

## Honorable mentions (less developed)

- **Schema.org / structured-data auto-generation** per entry type, kept in
  sync with field layout changes.
- **Cross-system reconciliation** — bind specific fields to external systems
  of record (Pricing → Stripe, Bios → BambooHR) and the agent flags drift in
  either direction.
- **Time-travel "what would have happened if"** — replay a chat session with
  a different answer at a branch point.
- **Custom module / plugin code review** as a built-in slash command that
  knows Craft 5 idioms (event lifecycle, eager loading, deprecated APIs).
- **Visitor-facing conversational search** — a heavily restricted MCP toolset
  exposed to unauthenticated visitors, answers cited back to entries.
- **Queue / error-log triage agent** — groups similar errors, links them to
  recent deploys, files issues with a proposed fix.
- **Field consolidation suggestions** — long-running scan that proposes
  merges of semantically duplicate fields across sections.
- **Audit-chat → release-notes** — every meaningful CMS change automatically
  produces a changelog entry suitable for a public release-notes section.

---

## If forced to pick three to start

1. **Embeddings infrastructure (#1)** — leverage for almost everything else
   on this list.
2. **Policy engine (#3)** — first feature that sells specifically to
   compliance-shaped buyers.
3. **"Why did this go live?" timeline (#4)** — genuinely impossible without
   the data Craft AI alone is collecting today.

Again: none of this is planned. It's a notebook.
