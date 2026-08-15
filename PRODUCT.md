# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

The founder (personal daily use) plus a small group of invited alpha testers, UK-based grocery shoppers. Phone-first, short-burst usage: scanning products in the kitchen after a grocery shop, logging what was eaten during the day, glancing at Today/Health stats. Desktop is secondary. The admin console is a founder-only tool and may assume a desktop viewport. Over the next year the audience stays invite-based; public launch is not a current design assumption.

## Product Purpose

foody is a personal food intelligence system: it knows what you buy, knows what you currently have, records what you eat, and turns that history into useful personalised nutrition guidance. Success is the closed loop working without founder intervention: sign up → scan → identify → resolve → add to pantry → consume → pantry decreases → nutrition history updates → Today/Week insight updates → a genuinely useful, pantry-aware recommendation. It is explicitly **not** "an AI that guesses calories from pictures."

## Positioning

Reliability over apparent intelligence. Product facts come from deterministic, source-backed data (barcode → Open Food Facts first; multimodal LLM identification only as fallback), every fact carries provenance and a confidence band, and the LLM never does arithmetic — totals, aggregates, and inventory deductions are pure deterministic services. Guidance is grounded in the user's actual pantry and consumption ledger, not generic diet advice.

## Operating Context

- **Scan:** in the kitchen after a grocery shop, phone camera on a packaged product; on-device barcode detection, then resolve/confirm/quantity into the pantry.
- **Eat:** during the day, quick consumption logging (all/half/custom amounts) that decrements the pantry ledger and snapshots nutrients.
- **Home/Health:** glanceable Today snapshot, weekly averages, component indicators, sparklines.
- **Admin console:** founder-only product CRUD, review queue, AI diagnostics; desktop-friendly.
- Bottom-nav mobile shell (Home / Pantry / **Scan** prominent centre / Eat / Health) with top-right profile; primary browser target is mobile Safari.

## Capabilities and Constraints

- Built and working (test-covered): auth loop, 4-step onboarding with an `onboarded` gate, event-sourced pantry ledger with cached balances and reconciliation, admin product CRUD, scan flow (on-device barcode → Open Food Facts lookup → confirm/correct → pantry), consumption with nutrient snapshots and clean edit/delete reversal, deterministic daily/weekly analytics, and an AI insight card.
- Remaining milestones: meal builder (M5), unknown-product research (M3), alpha hardening (M8).
- Live photo→AI identification requires an AI gateway key (`AI_GATEWAY=openrouter|vercel`); the barcode→Open Food Facts path works keyless.
- Architecture constraints future work must preserve: thin Livewire/Volt components delegating to services; AI behind capability interfaces (Prism) with swappable providers; deterministic maths only in pure services; confidence/status as enums; a low-confidence fuzzy match never silently becomes canonical identity.
- Known data gap: the Open Food Facts importer leaves `category` null, so category-based reads show "unknown" on real data until populated.
- Terminology in product voice: pantry, scan, eat, Today, canonical product, provenance, confidence.

## Brand Commitments

- The product is named **foody**, home **foody.gg** (founder, Aug 2026 — previously codenamed Jabba). The repo name "diet-tracker" is infrastructure, not brand.
- Inside the app the console is the **user's**, not the brand's: the shell calls out the logged-in user's name ("their dietary operating system"), not the foody wordmark (founder, Aug 2026).

## Evidence on Hand

- Real product data via Open Food Facts (strong UK grocery coverage); user-generated pantry/consumption history once in use.
- No testimonials, customers, case studies, press, or benchmarks exist. Future surfaces must not fabricate any.
- Health guidance carries plain disclaimers and stays clear of medical claims (brief §9.10, R4).

## Product Principles

1. **Reliability over apparent intelligence** — deterministic, source-backed data beats LLM memory; degrade to "unknown" honestly rather than guessing.
2. **Provenance and confidence are first-class** — every fact shows where it came from and how sure the system is.
3. **Deterministic maths** — the LLM never computes totals, aggregates, or deductions.
4. **Start narrow, finish excellently** — one packaged product → identify → pantry → consume → insight, done well, before widening scope.
5. **Thin frontend** — domain logic lives in services so the UI layer stays replaceable.
6. **Reward the loop** — success and completion triggers (a scan landing, a target hit, a logging streak held) deliver visible, felt reward moments; reinforcing the behaviour the product exists to drive is a first-class design requirement, not decoration (founder, Aug 2026).
7. **Never wait for the AI** — the interface never makes the user wait for AI, network, or database work: repeated behaviours (logging, scanning, corrections) stay instantly responsive, AI runs in the background against durable artifacts, high-confidence results apply automatically with undo, and confirmation is reserved for genuinely uncertain results. Speed never silently introduces bad food data — the provenance gate (principle 2) decides what may auto-apply (founder, Aug 2026).
