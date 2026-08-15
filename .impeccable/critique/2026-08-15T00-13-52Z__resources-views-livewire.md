---
target: core app interaction architecture (resources/views/livewire)
total_score: 22
max_score: 40
na_heuristics: 
p0_count: 2
p1_count: 2
timestamp: 2026-08-15T00-13-52Z
slug: resources-views-livewire
---
# UX Critique — Jabba Interaction Architecture
Method: dual-agent (A: interaction-architecture review · B: detector + live-browser evidence)
Lens: "The interface should never make the user wait for the AI."
Infrastructure facts: QUEUE_CONNECTION=sync (no true async anywhere); zero wire:navigate (every tab = full document load, confirmed live); camera is OS-owned <input type=file capture>; analyze() blocks a Livewire request through vision-LLM (2–6s) + resolver (OFF up to 10s timeout); weekly insight generation runs synchronously in Home's with() (2–8s on gpt-4o, first render of each week).

## Design Health Score
| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Analyze hides the photo behind a generic sweep with wrong copy; 8s barcode race near-silent; insight blocks Home render silently |
| 2 | Match System / Real World | 3 | "I ate one" excellent; scan→pantry (never →intake) contradicts "log my food" |
| 3 | User Control and Freedom | 1 | No undo anywhere; no back within scan; wrong-product destroys photo and dead-ends |
| 4 | Consistency and Standards | 2 | Two disclosure idioms, two delete-confirm idioms, morph-vs-reload mix |
| 5 | Error Prevention | 3 | Strong validation/clamps; double-tap can double-log (no idempotency; disable engages at request start) |
| 6 | Recognition Rather Than Recall | 2 | Eating-is-logged-from-Pantry must be memorized |
| 7 | Flexibility and Efficiency | 1 | Zero accelerators: no repeat-last, no auto-confirm at 1.0, no batch scan, mandatory quantity step |
| 8 | Aesthetic and Minimalist Design | 3 | Focused; done celebration oversized for a 3x loop |
| 9 | Error Recovery | 2 | Friendly fault states, but recovery forfeits context (photo, barcode) |
| 10 | Help and Documentation | 3 | 01/02/03 process print; nothing explains where scans go |
| Total | | 22/40 | Acceptable |

## Design Specificity Verdict
Split: the service layer is bespoke and undo-ready by construction (immutable ledger w/ compensating corrections, snapshot-on-consume, provenance bands MatchedBarcode/MatchedExact/MatchedFuzzy/Suggestion/NeedsResearch with thresholds, keyless barcode fast path, client downscaling). The interaction layer is default Livewire CRUD: full page loads, sync actions for show/hide, one-item wizard with ceremonial confirms, round trip per stepper tap. Visual language says instrument console; interaction model says form postback.
Deterministic scan: CLI detector 0 findings. Browser detector on /scan: 2x all-caps-body + 1x flat-type-hierarchy — treated as false positives against the sanctioned console world.
Agreement A/B: zero wire:navigate confirmed live (nav marker destroyed each tab); analyze removes the entire step body (wire:loading.remove); only 3 loading-state elements on /scan; barcode round trip 884ms via network vs 42ms local.

## Priority Issues
- [P0] Scan repeat loop serial+blocking end-to-end: 8 taps, 5–15s dead wait per item, no pipelining, camera cold-restarts; one $step state machine + sync analyze() + OS camera + sync queue. Fix: getUserMedia viewfinder w/ continuous barcode; client capture queue; analysis in real queue; wire:poll on ProductResolutionJob; results stack. (/impeccable shape → optimize)
- [P0] First Home render each week blocks on gpt-4o insight in with(). Fix: render null, lazy wire:init load, queue when driver exists. Cheapest large win. (/impeccable optimize)
- [P1] Ceremony on deterministic matches: 1.0 results pay 3 confirm taps; UI ignores its own provenance bands. Fix gate: auto-apply+UNDO at ≥0.85, confirm only 0.60–0.85 suggestion band, NeedsResearch → non-blocking card, photo retained. (/impeccable shape)
- [P1] Scan output and intake disjoint; Home has no logging entry point. Fix: "eating it now" on scan results, row-level "I ate one", Home quick-log w/ recents. (/impeccable shape)
- [P2] Navigation/micro-latency debt: adopt wire:navigate; client-side toggles/steppers/scanAnother; keep manual-add open across adds; dead pantry-updated event. (/impeccable optimize)

## North-star gap (shutter→re-arm→background→auto-apply)
Four independent locks, all required: (1) OS-owned camera; (2) single-slot $step state machine; (3) synchronous Livewire analyze; (4) QUEUE_CONNECTION=sync. Auto-apply half is near: resolver thresholds + isSuggestion already reach the view and are ignored. Rollback cheap via ledger corrections.

## Persona Red Flags
Alex: barcode detected+displayed yet must tap Identify; 1.0 match yet two confirms; paid round trip to reset screen; 23 taps/3 items. Casey: 8s silent race reads as hang → re-tap wipes state; photo hidden during analyze ("which item was that?"). Riley: refresh mid-analyze → server work completes, result discarded, no recovery; double-tap double-logs; no client timeout on analyze (stalled AI pins sweep forever).

## Minor Observations
Eat native confirm() vs pantry inline two-step — unify, prefer undo (restore guaranteed); keep photo visible during analyze + fix phase copy; insight dismiss lacks undo (service refresh() has no UI); wrong-product should carry uploaded photo into search-and-attach; retry() aliases scanAnother(); similar_text full-table scan inside blocking request.

## Questions to Consider
1. Why does the scanner stock a pantry instead of logging food?
2. What is the user confirming on a 1.0 match — the product, or the app's self-doubt?
3. Can a console be credible when its primary control (SCAN) surrenders the screen to the OS?

## Ranked plan (impact/taps/latency/complexity)
1. Unblock Home from insight (trivial). 2. wire:navigate + client toggles/steppers (low). 3. Undo replaces confirm (low). 4. Provenance-gated auto-apply w/ UNDO (medium). 5. Scan→intake bridge + row-level consume + Home quick-log (medium). 6. Real queue + background analysis + pollable results (medium-high; needs worker on Laravel Cloud). 7. getUserMedia viewfinder + capture queue + results stack (high; needs 6). 8. Interruption recovery (medium).
