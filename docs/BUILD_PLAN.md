# AI Pantry & Nutrition App — Build Plan & Job Breakdown

> **Source of truth:** `a018fcc1-ai_pantry_nutrition_mvp_plan.md` (the product brief). Every job below cites the brief section(s) it delivers, so we don't drift.
>
> **North Star (brief §23):** *A personal food intelligence system that knows what you buy, knows what you have, records what you eat, and turns that history into useful personalised nutrition guidance* — **not** "an AI that guesses calories from pictures."
>
> **Acceptance loop (brief §18):** Sign up → Scan → Identify → Resolve → Add to pantry → Consume → Pantry decreases → Nutrition history updates → Today/Week insight updates → AI gives a useful contextual recommendation. All without founder intervention.

---

## 1. Context — why this document exists

The brief is a rich pre-build spec produced with ChatGPT. It is deliberately open on several time-sensitive technical choices (§21, §22) and asks Claude Code to verify current framework/AI capabilities, refine the schema, and break the work into small testable milestones before writing code. This document is that output: an analysis, a set of concrete proposals, and a dependency-ordered job breakdown that autonomous agents can execute one milestone at a time.

The guiding constraints from the brief we will not violate:
- **Reliability over apparent intelligence** (§2.1) — deterministic/source-backed data beats LLM memory.
- **Confidence & provenance are first-class** (§2.2) — every product fact records where it came from and how sure we are.
- **Start narrow** (§2.3) — one packaged product → identify → pantry → consume → insight, done excellently.
- **Deterministic maths** (§8.9, §9.9) — the LLM never does arithmetic, totals, or inventory deductions.
- **Swappable AI providers** (§4.3) — no hard-coding a single model/vendor.
- **Thin frontend** (§4.1) — domain logic lives in services, not Livewire components, so a native/other frontend can be added later.

---

## 1b. Reframe (Aug 2026, founder-confirmed): the ledger is the product

**The consumption ledger — the complete record of what the user actually ate — is
the product's centre of gravity. The pantry is one high-fidelity SOURCE feeding
that ledger, not the frame everything hangs on.** The goal is a complete picture
of the user's diet and its connection to their health; a picture with holes every
time they leave the house is not a picture. Milestones to date were pantry-first
because that is where deterministic data was cheapest — correct sequencing, wrong
centre if left uncorrected.

Every meal enters the same ledger, tagged with its source context and fidelity:

| Tier | Context (`consumption_events.context`) | Source | Fidelity |
|---|---|---|---|
| 1 | `pantry` | Packaged / scanned product | Exact — label data, deterministic |
| 2 | `home_cooked` | Composed from pantry components | Near-exact — known ingredients, chosen portions |
| 3 | `eating_out` | Restaurant / cafe / takeaway | Estimated — marked `estimated`, shown with `~` |
| 4 | `eating_out` (no figures) | "Ate out, details unknown" | Coarse — but the meal is ON the record |

Operating principles that follow (extend §2.1, never contradict it):

- **Completeness beats precision.** Every meal at ±20% builds a truthful week;
  40% of meals at ±2% builds a lie. The deadliest failure is the skipped meal,
  so the lowest-friction flow belongs to the hardest context (eating out).
- **Estimates wear their tilde.** Estimated figures are stored as given, marked
  `estimated`, rendered `~720`, and unknown figures stay NULL — never faked.
  This is how tier-3 data coexists with §2.1 instead of violating it.
- **Capture fits the user's existing moment** (the Hevy principle): the plate is
  in front of them — camera-first entry, three contexts (packaged / home-cooked /
  eating out), "usuals" one tap, done in seconds. The app must fit the flow the
  user already has, not demand a new one.
- **The pantry pays off as grounding, not as gatekeeping.** For home-cooked
  meals the pantry turns AI dish recognition (Phase B) from open-world guessing
  into recognise-and-select against ~30 known items — our structural advantage
  over calorie-camera apps. But no flow ever REQUIRES pantry data to log a meal.
- **Insights inherit the tiers.** The insight engine may weight by fidelity and
  should surface cross-context findings ("eating-out days run ~600 kcal higher,
  half the protein"). Connecting the diet record to health outcomes is the goal;
  pantry intelligence is a means.

Capture-flow phasing: **A (shipped with this note)** — meal logging for all
three contexts: home-cooked compose from pantry (portion chips), eating-out
quick log with honest estimates, usuals-from-history (one-tap re-log for eating
out, prefill-for-confirmation for home-cooked). **B** — photo + AI proposal:
pantry-grounded recognition for home-cooked, dish-class estimation for eating
out, landing on the same confirm screens. *(Eating-out estimation shipped
Aug 2026: EatingOutEstimator — dish + venue -> estimated figures with
confidence + stated basis, chain menus preferred, user confirms/edits, manual
entry is the keyless fallback. Photo remains open.)* **C** — portion-from-photo, off-pantry
ingredient suggestions, free-text ("big bowl of the usual porridge").

---

## 2. Current-stack findings (verified Aug 2026)

| Area | Decision | Why |
|---|---|---|
| Framework | **Laravel 12** | Current stable; long support; matches brief §4.1. |
| Frontend | **Livewire (official starter kit)** + Volt + **Tailwind** + Flux UI | Official starter kit is the "cleanest structure" the brief asks for (§21 Q2/Q3). Note: the current starter kit ships **Livewire 4** — see open decision D1. |
| Auth | **Starter-kit auth** (Breeze-equivalent built into the kit) | Brief §6.2 needs register/login/logout/reset/remember only — no complex OAuth (§6.7). |
| DB | **PostgreSQL 16** | Present in env; brief §4.1. |
| Queue/cache | **Redis** (installed) | For async research/insight jobs (§4.1, §20 Phase 3/7). |
| AI layer | **Prism PHP** behind our own capability interfaces | Provider-agnostic (OpenAI/Anthropic/Gemini/DeepSeek/Ollama/OpenRouter), first-class **structured output** + **multimodal images** + tools — exactly §4.3's requirements. |
| AI gateway | **OpenRouter** via Prism (recommended, see D2) | One key → many models → satisfies the benchmarking goal (§14) by changing a config string. |
| Authoritative product data | **Open Food Facts** (see D3) | Free, open barcode+nutrition DB with strong UK grocery coverage → deterministic lookup before any LLM (§7.5 source hierarchy, §2.1). |
| Object storage | S3-compatible in prod; local disk in alpha | Brief §4.1; image retention policy (§21 Q43). |

---

## 3. Proposed ideas & refinements (our value-add on top of the brief)

These are recommendations we believe strengthen the MVP. Each is optional and flagged where it touches an open decision.

1. **Barcode-first fast path before any LLM.** The brief already says "barcode should be exploited where practical" (§7.2 Step 2, §7.3). We go further: read the barcode **on-device** in the browser (`BarcodeDetector` API with a JS fallback lib) during Scan. A barcode → Open Food Facts lookup is *free, deterministic, and near-instant*, and only falls back to multimodal LLM identification when no barcode is readable or no match is found. This slashes AI cost/latency (§19 metrics) and maximises exact-match rate — directly serving §2.1. *(Does not contradict §7.15's "no barcode-scanner-only experience": barcode is a fast path inside the unified Scan flow, not a separate core flow.)*

2. **Open Food Facts as the primary canonical seed + authoritative source.** Turns most known-product scans into deterministic lookups and dramatically reduces how often the expensive "unknown product research" workflow (§7.4/§7.5 — the riskiest, priciest piece) has to run. Provenance is recorded as `source_type = open_food_facts`.

3. **Prism + thin capability interfaces.** We define `ProductIdentifier`, `ProductResearcher`, `ProductValidator`, `MealInterpreter`, `DietInsightGenerator` (brief §4.3/§13) as interfaces; Prism-backed implementations sit behind them. Domain code depends only on the interfaces → swapping models/providers never touches domain logic (§21 Q22).

4. **AI job diagnostics table from day one** (`ai_jobs`). Cheap to add, and it's the backbone of the model-benchmarking plan (§14) and admin diagnostics (§11). Records task type, provider, model, latency, tokens/cost, status, retries, confidence.

5. **Event-sourced pantry ledger with a cached balance.** Brief §7.10/§8.7 wants an auditable ledger. We store immutable `pantry_transactions` **and** a maintained `pantry_items.current_quantity` (derived from the ledger, updated in the same DB transaction) so the Pantry screen is fast without recomputing history. Reconciliation test proves cache == sum(ledger).

6. **Nutrition snapshots on consumption.** Consumption rows copy the nutrient values at the time eaten (§12 `consumption_items`) so historical days never change when a product is later reformulated (§10.3 versioning).

7. **Schema simplification for the alpha (§6.5 "don't over-normalise").** Collapse `user_profiles` + goal + dietary preferences + allergies into **one `user_profiles` row** using typed columns + JSON for the list-y bits (preferences, allergies, avoided foods). Split out later only if needed.

8. **Deterministic nutrition engine as a pure, unit-tested service** (`NutritionCalculator`) — per-100g ↔ per-serving conversions, meal totals, daily/weekly aggregates. No framework/DB coupling → trivial to test (§21 Q48).

9. **Confidence & status as enums, not free text** — resolution confidence bands and product `verification_status` (`pending`, `auto_verified`, `needs_review`, `verified`, `rejected`, `superseded`) per §10.2. A fuzzy match below threshold **never** silently becomes canonical identity (§7.2 Step 3).

### Risks / conflicts flagged for you
- **R1 — Livewire 4 newness.** The official starter kit now ships Livewire 4. It's excellent but newer than Livewire 3. Decision D1 lets you pin v3 if you'd rather have the more battle-tested version for an alpha.
- **R2 — Unknown-product research is the hardest, most expensive, least deterministic piece.** We deliberately push it to Phase 3, keep it fully behind the validation gate (§10.2), and lean on Open Food Facts (idea #2) to minimise how often it runs.
- **R3 — Shared canonical DB = shared data quality.** One user's bad correction shouldn't poison everyone. Mitigation: corrections are evidence, not truth (§7.6); promotion to canonical requires the validation layer + admin review queue (§10.2, §11).
- **R4 — Health guidance liability.** Add plain disclaimers and keep out-of-scope medical claims out (§9.10, §21 Q47) from the first insight shipped.

---

## 4. Architecture at a glance

```
Livewire pages (thin)  ──►  Application Services (all domain logic)  ──►  PostgreSQL
   Home/Scan/Pantry           PantryService, ConsumptionService,           (Eloquent)
   Eat/Health/Profile         NutritionCalculator, NutritionAnalytics,
   Admin                      ProductResolver, CanonicalProductService
                                        │
                                        ├─► AI capability interfaces ──► Prism ──► provider(s)
                                        │     ProductIdentifier/Researcher/Validator/
                                        │     MealInterpreter/DietInsightGenerator
                                        │
                                        └─► Queued jobs (Redis): ResearchUnknownProductJob,
                                              ValidateProductCandidateJob, GenerateDietInsightJob
```
Rule: **Livewire components call services; services never depend on Livewire.** (§4.1, §21 Q5, §22.14)

---

## 5. Refined data model (MVP)

Tables (nullable/index notes are the important refinements per §21 Q6–Q12):

- **users** — starter-kit default.
- **user_profiles** — `user_id` (unique FK), `primary_goal` (enum), `date_of_birth?`, `sex?`, `height_cm?`, `weight_kg?`, `activity_level?`, `dietary_pattern?`, `dietary_preferences` (json), `allergies` (json), `avoided_foods` (json). *(idea #7)*
- **canonical_products** — `gtin?` (**unique when present**, indexed), `brand`, `name`, `variant?`, `pack_size_value?`, `pack_size_unit?`, `category?`, `primary_image_path?`. Fuzzy-match index on `(brand, name)`.
- **product_versions** — `canonical_product_id`, `serving_basis` (`per_100g`|`per_serving`), `serving_size_value?`, `serving_size_unit?`, macro columns (calories, protein, carbs, sugars, fat, saturated_fat, fibre, salt), `ingredients?`, `allergens?` (json), `effective_from`, `verified_at?`, `status` (enum §9 above). Preserve original basis; derive the other deterministically (§7.12).
- **product_sources** — `product_version_id`, `source_url?`, `source_type` (enum: `open_food_facts`|`manufacturer`|`retailer`|`label_ocr`|`user_confirmed`|`llm_estimate`), `retrieved_at`, `confidence`, `evidence_summary?`. Provenance (§7.13).
- **product_resolution_jobs** — the scan audit trail (§12): detected fields, `detection_confidence`, `matched_product_id?`, `status`, `model_provider?`, `model_name?`, `latency_ms?`.
- **pantry_items** — `user_id`, `canonical_product_id`, `current_quantity`, `quantity_unit`, `purchased_at?`, `expiry_date?`. *(cached balance, idea #5)*
- **pantry_transactions** — immutable ledger: `pantry_item_id`, `type` (`purchase`|`consume`|`discard`|`correction`|`manual_remove`), `quantity_delta`, `unit`, `linked_consumption_event_id?`, `occurred_at`.
- **consumption_events** — `user_id`, `type` (`single`|`meal`), `name?`, `consumed_at`, total macro columns.
- **consumption_items** — `consumption_event_id`, `canonical_product_id?`, `product_version_id?`, `quantity`, `unit`, **snapshotted** macro columns (idea #6).
- **ai_jobs** — `task_type`, `provider`, `model`, `latency_ms`, `input_tokens?`, `output_tokens?`, `cost?`, `status`, `retries`, `confidence?`, `result_status?`. *(idea #4, §11, §14)*
- **ai_insights** — `user_id`, `insight_type`, `period_start`, `period_end`, `title`, `body`, `structured_inputs` (json), `provider`, `model`. (§12)

---

## 6. Job breakdown (dependency-ordered milestones)

Each **Job** = one testable milestone with acceptance criteria (AC) and the brief section it satisfies. Jobs within a milestone marked **∥** can be built by parallel agents once the milestone's prerequisites exist.

### Milestone 0 — Foundation *(brief §20 Phase 0)*  ⟶ prerequisite gate for everything
- **J0.1 Scaffold.** Laravel 12 + Livewire starter kit + Tailwind, PostgreSQL `.env`, Redis, storage disk, error logging.
  - AC: `php artisan test` green on a fresh app; app boots; DB migrates.
- **J0.2 Auth & session.** Register, login, logout, password reset, "remember me". (§6.2)
  - AC: feature tests cover the full auth loop.
- **J0.3 Mobile shell & nav.** Bottom nav Home / Pantry / **Scan (prominent)** / Eat / Health + top-right profile. Calm/crisp Tailwind base (§5, §15).
  - AC: renders app-like on iPhone Safari viewport; Scan is visually primary.
- **J0.4 Onboarding + profile.** 3–4 lightweight steps (goal → prefs → optional body info → confirm), progressive disclosure; profile/settings incl. **data deletion** (§6.3, §6.4, §21 Q45).
  - AC: new user completes onboarding; profile edit + account deletion work; feature-tested.

### Milestone 1 — Canonical product + pantry skeleton *(§20 Phase 1)* — *prove the data model before AI*
- **J1.1 Migrations & Eloquent models** for all §5 tables (canonical/version/source/pantry/ledger). ∥
- **J1.2 NutritionCalculator service** (pure, unit-tested): basis conversion, totals. (§8.9) ∥
- **J1.3 PantryService** with ledger: add/consume/discard/correct → cached balance, reconciliation. (§7.7, §8.7) 
- **J1.4 Admin console (custom Livewire)** — product CRUD, browse/search, manual product+version creation. (§11 Products, §21 Q39) ∥
- **J1.5 Pantry & product-detail UI** — list "what do I have", tap-through detail/actions. (§7.8, §7.9)
  - Milestone AC: create a product + version by hand in admin → manually add to a user's pantry → pantry shows correct quantity & nutrition; NutritionCalculator unit tests pass; ledger reconciles.

### Milestone 2 — AI product identification *(§20 Phase 2)* — needs a provider key (D2)
- **J2.1 Prism install + AI capability interfaces + `ai_jobs` logging.** (§4.3, §13, idea #4) 
- **J2.2 `ProductIdentifier`** (multimodal): image → structured `{brand,name,variant,pack_size,barcode,confidence}` (§7.2). ∥
- **J2.3 On-device barcode read + Open Food Facts lookup** fast path (idea #1/#2, §7.3). ∥
- **J2.4 `ProductResolver`** — resolution order barcode → exact → fuzzy → unknown, with confidence thresholds; a low-confidence fuzzy match never auto-canonicalises. (§7.2 Step 3, §21 Q13/Q14)
- **J2.5 Scan flow UI** — capture/upload → progress → **"Is this right?"** confirm/correct → quantity → add to pantry; corrections stored as evidence. (§7.2–§7.7, §16.2)
  - Milestone AC: photo/barcode of a real packaged product → correct known-product identification → confirm → lands in pantry with nutrition; a wrong guess is correctable and the correction is recorded.

### Milestone 3 — Unknown-product research *(§20 Phase 3)* — **highest risk (R2)**; fully gated
- **J3.1 `ResearchUnknownProductJob`** (queued) → search trusted sources → collect evidence → structured extraction. (§7.4, §7.5)
- **J3.2 `ProductValidator` + validation layer** → candidate + evidence → status transitions; never auto-truth. (§10.2)
- **J3.3 Admin review queue + resolution-failure view.** (§11)
- **J3.4 Async return to browser** via Livewire polling/events while research runs. (§21 Q31)
  - Milestone AC: an unseen product with no OFF match runs research async, produces a candidate with sources + a status, surfaces in the review queue, and (if auto-verified) returns to the user.

### Milestone 4 — Consumption *(§20 Phase 4)*
- **J4.1 ConsumptionService** — consume single item, partial amounts (all/half/custom; units/g/ml/fraction), ledger deduction, **nutrient snapshots**, edit/delete with correct reversal. (§8.1–§8.7, idea #6)
- **J4.2 Consumption history UI** (Today list, edit/delete, inspect components). (§8.6)
  - Milestone AC: "I ate one" decrements pantry, writes a snapshotted consumption event; edit/delete reverses cleanly; totals deterministic.

### Milestone 5 — Meals *(§20 Phase 5)*
- **J5.1 Structured meal builder** — pick pantry ingredients + quantities → deterministic totals → one meal event → deduct each ingredient. (§8.4) ∥
- **J5.2 (Optional) Natural-language meal entry** via `MealInterpreter` → structured candidates mapped to pantry items; ask only when ambiguity matters. (§8.5) — *does not block release.*
  - Milestone AC: a multi-ingredient meal logs correct totals and deducts every ingredient from pantry.

### Milestone 6 — Health analytics *(§20 Phase 6)*
- **J6.1 NutritionAnalyticsService** — daily + rolling 7-day aggregates, trend deltas, component indicators; deterministic only. (§9.4, §9.8, §8.9) ∥
- **J6.2 Home/Today + Weekly UI** — snapshot, indicators (Good/Low/…), lightweight charts/sparklines that play well with Livewire. (§9.3, §9.4, §15.2, §21 Q38) ∥
  - Milestone AC: logged consumption produces correct Today and 7-day figures and component indicators.

### Milestone 7 — AI insights *(§20 Phase 7)*
- **J7.1 DietInsightGenerator** — deterministic structured analytics **in**, human-readable prioritised insight **out** (1–2 high-confidence observations, pantry-aware). Disclaimers (R4). (§9.6, §9.7, §9.8)
- **J7.2 Insight UI** — "Your focus this week" card + Why/Show me/Dismiss feedback. (§9.6)
  - Milestone AC: with a week of data the app surfaces one genuinely useful, pantry-aware, non-overstated recommendation → **closes the §18 acceptance loop.**

### Milestone 8 — Alpha hardening *(§20 Phase 8)*
- Error/retry states, product merge/alias, admin AI diagnostics + cost/latency, mobile Safari QA, image-retention policy, privacy/deletion audit, invites, product-eval harness seed (§14). (§20 Phase 8, §21 Q42–Q52)

---

## 7. Agent orchestration strategy

**Why not one giant agent:** the brief (§22.2, §22.7) insists on small testable milestones. So we gate on Milestone 0, then fan out.

- **Sequential gate:** One agent builds **Milestone 0** (scaffold is a shared prerequisite — parallelising here would cause file conflicts). Verify green, commit.
- **Fan-out within a milestone:** Once M0 exists, jobs marked **∥** go to parallel agents (e.g., in M1: one agent does migrations+models J1.1, another the pure `NutritionCalculator` J1.2, another admin CRUD J1.4 — they touch different files). A synthesis step wires them and runs the milestone AC.
- **Collaborative review:** Each milestone ends with a review agent checking against the brief section + AC before we move on (adversarial verify for the deterministic-maths and ledger-reconciliation invariants).
- **Isolation:** parallel code-writing agents use git worktrees to avoid clobbering each other.
- **Human checkpoints:** after each milestone AC passes, and at the open decisions below.

---

## 8. Decisions (LOCKED — confirmed by founder)

- **D1 — Frontend: Livewire 4 (official starter kit).** ✅ Locked.
- **D2 — AI gateway: env-selectable via Prism (OpenRouter default, Vercel optional)** (one key, model-swap by config → serves benchmarking §14). ✅ Locked. Needed before Milestone 2. **Now gateway-agnostic:** a single switch `AI_GATEWAY=openrouter|vercel` selects the Prism provider (and its base URL + key) for ALL capabilities — `openrouter` uses `OPENROUTER_API_KEY`, `vercel` uses `AI_GATEWAY_API_KEY` against `https://ai-gateway.vercel.sh/v1`. Both are OpenAI-style, same `creator/model` ids. Vercel is registered as a custom Prism provider (`PrismManager::extend`, reusing Prism's OpenAI provider); domain code is untouched and reads only `config('ai.*')`.
- **D3 — Open Food Facts as authoritative source: YES** (idea #2). ✅ Locked.
- **D4 — Internal API from day one: NO** — services stay frontend-agnostic; add an API only when a second client appears (§21 Q4). ✅ Locked.

---

## 10. Progress log

- **Milestone 0 — Foundation: ✅ COMPLETE** (`3430eda`). Official Livewire starter kit (Laravel 12.66, Livewire 4.4, Volt, Flux, Tailwind v4); PostgreSQL (`diet_tracker` DB, role `diet_user`) + Redis; auth loop, mobile shell (Home/Pantry/**Scan**/Eat/Health), 4-step onboarding, profile hub with real account deletion. Domain logic in `app/Services/ProfileService.php`; components thin. **38 tests passing.** Deviations from §5: post-auth landing renamed `dashboard`→`home`; added `onboarding_completed_at` to gate onboarding. See `docs/FOUNDATION_NOTES.md`.
- **Milestone 1 — Canonical product + pantry skeleton: ✅ COMPLETE.** Backend `a96465d` (10 tables/models/factories, 6 enums, pure `NutritionCalculator` + `NutrientValues`, `PantryService` ledger with cached balance + reconciliation). UI `6f47e5d` (admin product CRUD gated by `is_admin` + `app:make-admin`, Pantry list/detail/actions via `PantryService`, manual add, end-to-end acceptance test). 105 tests green.
- **Milestone 2 — AI product identification: ✅ COMPLETE.** Backend `8a11eb2` (Prism `prism-php/prism ^0.100`, `App\AI\Contracts\ProductIdentifier` + Prism impl, `AiJobLogger`→`ai_jobs`, Open Food Facts client+importer, `ProductResolver` barcode→exact→fuzzy→unknown with 0.85/0.60 thresholds, nutrient columns relaxed to nullable + null-propagation in `NutrientValues`). UI `d8b040e` (multi-step Scan flow: capture → on-device `BarcodeDetector`/@zxing barcode → resolve → "Is this right?" confirm/correct → quantity → add to pantry; graceful key-absent + unknown fallbacks). 145 tests green.
- **Push status:** ✅ write access granted; auto-pushing each milestone. Branch `claude/app-planning-breakdown-unjb8x` up to `d8b040e`.
- **Open item — live AI key:** photo→AI identification needs `OPENROUTER_API_KEY` (D2) to run live; barcode→OFF path works keyless. Key requested from founder.
- **Sequencing note:** with "build now, key later", proceeding M4 (Consumption) → M6 (Health analytics) → M7 (Insights, fake-tested) to close the §18 acceptance loop structurally without keys; M3 (unknown-product research — needs live AI + a web-search provider decision) deferred until keys/search are settled.
- **Milestone 4 — Consumption: ✅ COMPLETE** (`7b81952`). `ConsumptionService` (nutrient snapshots, ledger reversal on edit/delete keeping `reconcile` true), consumption history on Eat. 161 tests.
- **Console redesign (design pass, Aug 2026): ✅ COMPLETE.** The core app (shell + Home/Pantry/Scan/Eat/Health + pantry item) rebuilt as the personal food intelligence console (design world codenamed "Jabba", renamed **foody** in Aug 2026 — home foody.gg): TE-inspired dark instrument world (chassis/plate/seam material, Archivo + Fragment Mono + DSEG7 self-hosted type, fixed functional signal palette), reward-the-loop success moments (green stamped banners, LED sweeps, counters), appearance toggle retired. Direction contract lives in `components/layouts/app.blade.php`; comps + approvals under `.impeccable/`; PRODUCT.md carries the product-truth additions (user-named console, reward principle). Services/routes/behavior untouched; suite green (215 passed).
- **Milestone 6 — Health analytics: ✅ COMPLETE** (`ff78059`). `NutritionAnalyticsService` (daily + rolling 7-day + trend deltas + component indicators, honest unknown handling), Home/Today + Health/Weekly UI with inline-SVG sparklines. 182 tests.
- **Milestone 7 — AI Insights: ✅ COMPLETE** (`9900342`). `DietInsightGenerator` with a deterministic **rule-based default** (works with no key) + Prism LLM upgrade; pantry-aware "Your focus this week" card (Why / Show me / Dismiss); cached per week, queue-ready. **207 tests.** **§18 acceptance loop is now structurally closed.**
- **Deployment: ✅ LIVE on Laravel Cloud** (founder-provisioned Serverless Postgres 18 + Valkey cache; branch auto-deploys). Live photo-AI still needs `OPENROUTER_API_KEY`; barcode + rule-based insights work keyless.
- **D2 revisited — gateway-agnostic: ✅ DONE.** The gateway is env-selectable via a single switch `AI_GATEWAY=openrouter|vercel` (default `openrouter`); it resolves the Prism provider + base URL + key for every capability with **no domain-code changes** (only `config/ai.php`, `config/prism.php`, `AiServiceProvider`). Vercel AI Gateway (OpenAI-compatible, `https://ai-gateway.vercel.sh/v1`, `AI_GATEWAY_API_KEY`) is registered as a custom Prism provider via `PrismManager::extend`, reusing Prism's OpenAI provider. Key-absent grace holds for whichever gateway is selected. Also serves §14 benchmarking.
- **Interaction architecture — "never wait for the AI" (UX pass, Aug 2026): ✅ COMPLETE.** Product principle 7 recorded and built end-to-end from the archived critique (`.impeccable/critique/`, scored 22/40): (1) the weekly Focus card loads via wire:init so Home/Health never block on generation, with dismiss-undo; (2) all internal navigation is wire:navigate (SPA-style), pure disclosure state moved client-side, pantry manual-add stays open across adds; (3) confirms became undoable automatic actions where the ledger guarantees restoration (Eat delete = 6s undo window, consume/eat toasts carry UNDO); (4) queue infrastructure: `scan_captures` + `ProcessScanCapture` + `ScanCaptureService` with the **provenance gate** (barcode/exact 1.0 + fuzzy ≥0.85 auto-apply undoably; 0.60–0.85 suggestion band always asks; below settles honestly) — worker setup documented in DEPLOY.md Part B2, sync driver remains a working inline fallback; (5) the north-star scanner: owned getUserMedia viewfinder with live barcode reads, instant-re-arm shutter, per-capture POST drop-box (`/scan/captures`), polling results stack with eat-now/undo cards, OS file-input fallback; (6) intake bridge: pantry row-level EAT key (natural portion, undoable) and Home "Log a meal" key. 271 tests green.
- **Clutter/copy sweep (design pass, Aug 2026): ✅ COMPLETE.** From the product-wide critique (30/40, archived in `.impeccable/critique/`): the silkscreen test ("if it wouldn't be etched on the faceplate, it isn't permanent copy") deleted ~14 helper sentences; trust fixes (per-metric trend direction-goodness on Health — salt/sat-fat up no longer renders green; ghost "I ate one" empty state; the leftover "P" logo on welcome; "gateway key" ops jargon; silent eatOne now toasts); redundancy sweep (one canonical disclaimer inside the targets disclosure, Trends-standby module and Appearance page removed, insight card lives on Health only); behavioural re-hierarchy (usuals above the context quiz in log-meal, pantry EAT key promoted, Pantry defaults to Stock, Eat row actions behind the row tap, pantry-item Consume above collapsed Nutrition, scan suggestion-band cards isolated in amber). Home dropped 138→66 visible words.
- **Tranche 4 — the resident chef (Aug 2026): ✅ COMPLETE.** The chef lives IN the pantry as a compact card under the stock list: time-of-day slotting (breakfast/lunch/dinner off the score engine's meal schedule), one whole-day generation persisted per-slot to the durable `chef_suggestions` table (the pantry renders instantly from the artifact; the model is consulted at most once a day plus explicit refreshes), ingredient imagery leading, one-tap **Cooked this** prefills home-cooked compose and links the logged event back so the card flips to COOKED · LOGGED — cooked suggestions are records and never overwritten. The old STOCK/CHEF view switch and day-plan page retired. Future: user-set meal times feed the slotting.
- **Tranche 5 — outcome rewards (Aug 2026): ✅ COMPLETE.** The escalation ladder's top tier: `FoodyScoreService` mints a `day_closed` milestone on a genuinely finished day — firm score, day-completeness ≥ 0.9, AND food actually logged today (completeness alone is time-based, so a late empty evening must never 'close' a day nobody lived). Home renders the close as one authored moment (silkscreen DAY CLOSED, an LED sweep that runs once on the fresh mint, one spoken line carrying the consecutive-logged-days streak) and the kcal readout, its scale marker and the engraved goal band all light `good` when the day's energy lands inside the goal's own full-credit band — the same band the engine credits, so the lamp never disagrees with the score. The routine close stays off the share card (distinctive achievements only). Fixed in passing: `value-settle`'s terminal keyframe declared `currentColor`, which resolved to the inherited ink and silently overrode any state colour on a settling readout.
- **Next design tranche (queued):** (6) front-door console pass (welcome/auth/onboarding/profile/settings out of the emerald starter dialect).
- **Foody Score v1 (Aug 2026): ✅ COMPLETE.** The deterministic, versioned score engine per the locked spec: `config/foody_score.php` (every parameter, versioned `foody_score_v1`), pure `ScoreEngine` (five pillars, goal profiles incl. new Performance/Gut-health goals, intraday expected-fraction evaluation, plateau/Gaussian curves, three confidence dimensions, reason codes, ranked insight candidates), `InputAssembler` (all I/O; learned meal-schedule expected fraction; plant evidence with herbs/spices excluded), `FoodyScoreService` (today-only recording, evidence-sensitive display smoothing, personal milestones), `ScoreInsightService` + one-voice wording (deterministic templates default, single Prism call when keyed — the LLM words, never ranks), Home rebuilt to the spec's IA (score headline with building/provisional/firm states, kcal + P/C/F/Fibre cluster, contextual read, lazy signals ≤3, 7-day trace, action), manual calorie/macro targets in Profile (explicit targets scored with tighter tolerance), daily share card + milestones via `SharePayloadService`. Historical rows keep their minting version, never rewritten. Micronutrient pillar is coverage-gated OFF (schema has no micronutrient columns — unknown is never zero); the engine's full micro path is live behind config for when columns exist.
- **DEFERRED — hydration (Foody Score spec §20):** hydration is intentionally OUT of Foody Score v1. Product dependency to record: Foody needs a deliberate hydration logging/measurement flow (a capture surface + a `hydration_events` style model) before hydration becomes eligible for scoring; when it lands, it enters as a new pillar/component under a bumped `target_rules_version`, never a silent re-weighting.
- **Shareability foundation (Foody Score spec §18):** `SharePayloadService` is the stable share contract (self-referential only — no ranks, no leaderboards, nothing that lets a consumer imply "87 beats 82"); `foody_milestones` stores personal records (first firm score, personal bests, steady streaks). V1 surface: the Home share card + Web Share text. Future: rendered share images (needs an image pipeline), weekly cards, private circles comparing trends/consistency/milestones — all consume this payload without touching the scoring model.
- **Unified capture (Aug 2026): ✅ COMPLETE.** SCAN is the single camera entrypoint: capture first → triage inside the one specialised identification call (`kind`: packaged_product / ingredient_or_food / prepared_meal / unknown) → route automatically. Products/ingredients keep the untouched provenance gate; prepared meals settle as `Meal` captures whose specialised `MealPhotoInterpreter` reading is taken AT CAPTURE TIME (serverless disk can't promise a later read) and persisted, feeding the `?capture=` bridge that opens log-meal prefilled (dish, matched pantry components, also-seen); genuinely-uncertain triage (< 0.5 confidence) asks "What am I looking at?" with Product/Ingredient/Meal chips that requeue with the user's claim as an identification hint. Barcode path unchanged and fully deterministic. Progress feedback maps to REAL pipeline stages (`stage` column: looking/identifying/checking/finishing) with deadpan secondary lines; a stalled capture says "still working" instead of progressing fictionally. Shopping sessions: the first stored product offers "Adding groceries?" once — accepted, cards compact to name+IN PANTRY, the counter carries the rhythm, DONE stamps a session summary. Log retains all non-camera methods; its two photo-upload shortcuts are retired in favour of the scanner (machinery reused by the bridge). Queue posture: worker = normal path (DEPLOY B2), poll rescue = logged safety net.
- **Remaining:** M5 (meal builder), M3 (unknown-product research), M8 (alpha hardening: S3 image persistence, cost/latency, Safari QA, merge/alias tooling) — the queue worker moved from M8 into the UX pass (needs founder provisioning per DEPLOY.md B2). Known gap: OFF importer leaves `category` null on rows imported before Aug 2026 (new imports store the most specific category tag), so plant-diversity evidence stays thin until items are rescanned (candidate for M8/M3 backfill).

---

## 9. Verification strategy (per milestone)

- **Unit:** `NutritionCalculator` (conversions/totals), analytics aggregates, resolution-confidence banding. (§21 Q48)
- **Feature:** auth loop; pantry add/consume/discard **ledger reconciliation** (cache == Σ ledger); consumption edit/delete reversal; meal deduction. (§21 Q49)
- **Integration:** product resolution barcode→exact→fuzzy→unknown with fixtures for known & unknown products. (§21 Q50/Q51)
- **AI eval harness (separate from app tests):** small seed set of grocery images scored for identification/extraction accuracy + cost/latency, logged via `ai_jobs`. (§14, §21 Q52)
- **End-to-end (the real bar):** a fresh user completes the §18 acceptance loop unaided.
