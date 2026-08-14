---
version: 1
slug: "resources-views-components-layouts-app-blade-php"
primary_target: "resources/views/components/layouts/app.blade.php"
related_targets: ["resources/views/livewire/home.blade.php","resources/views/livewire/scan.blade.php","resources/views/livewire/pantry.blade.php","resources/views/livewire/eat.blade.php","resources/views/livewire/health.blade.php"]
---

# Core app: personal food intelligence console

Scope: app shell + Home, Scan (full flow), Pantry, Eat, Health. Visitor mode: Operate on every screen.

Audience/job: founder + invited alpha testers, phone-first short bursts (kitchen scanning, daytime eat-logging, evening Health glance). Primary action per screen: Home = glance today then scan/log; Scan = capture→confirm→quantity→pantry; Pantry = read stock; Eat = read/edit today's log; Health = read week.

Chosen direction (approved 2026-08-14): Teenage Engineering dark console, 60% precision instrument / 25% modern terminal / 15% restrained retro-futurism. Governing comps: .impeccable/mocks/home-comp-a.png (Home + system grammar, with recorded amendments in its sidecar) and .impeccable/mocks/scan-success-comp.png (reward grammar + scan done step). Kept donations: comp B's value+chip indicator rows; comp C's terminal LOG feed (Eat). Declined: comp C's glossy bevels (skeuomorphism).

Memorable moment: the seven-segment TODAY readout on Home; the machine-stamped green ADDED TO PANTRY moment on scan success.

Reward grammar (binding, from founder): success/completion triggers (scan lands, target hit, streak held, day logged) celebrate in hardware vocabulary — solid green color fields, LED cell sweeps, stamped checks, incrementing mono counters (STREAK +1, PANTRY N ITEMS). Never confetti/cartoon effects.

Shell: top bar = orange index dot + logged-in user's first name (uppercase mono) + tiny FOOD OS tag, date/mode right; JABBA brand lives only on auth/welcome. Bottom nav = hardware control strip, five keys, raised solid-orange SCAN key dominant.

## Implementation-fidelity inventory (medium per region)

| Region | Source comp | Medium |
| --- | --- | --- |
| Seven-seg kcal numerals | A | webfont DSEG7 Classic (fallback: authored SVG segments) |
| Tick-mark scale + orange index marker | A | inline SVG |
| Macro tiles with bar meters | A | HTML/CSS |
| STREAK day cells | A amendment | HTML/CSS (7 cells, orange fills) |
| Indicator rows: label + mono value + band chip | B donation | HTML/CSS |
| FOCUS module + cyan dot | A | HTML/CSS |
| Control-strip nav, raised orange SCAN key | A | HTML/CSS (subtle bottom edge, no gloss) |
| Green ADDED TO PANTRY banner + black check | scan comp | HTML/CSS + SVG check |
| LED cell sweep row | scan comp | HTML/CSS grid + CSS animation |
| Reward chips (STREAK +1 / PANTRY N) | scan comp | HTML/CSS |
| Provenance line (BARCODE · SRC · CONF) | scan comp | HTML/CSS mono text |
| Terminal LOG feed | C donation | HTML/CSS mono rows |
| Sparklines (Health) | existing component | inline SVG restyled (stepped/quantized line) |
| Type: labels/prose | all | Archivo (self-hosted variable) |
| Type: data/numbers | all | Fragment Mono (self-hosted), tabular by nature |
| Motion | all | CSS only: crisp snaps, LED sweep, counter tick; no springs |

Compositional commitments: nav = 5 labeled keys (no icons except scan glyph optional); Home hierarchy = readout ≫ macro tiles > streak > indicators > focus; one spacing rhythm; modules self-label with uppercase micro-labels top-left; hairline seams not shadows.

Unresolved: none blocking. Credits exhausted for further comps; Home amendments audited from sidecar list at finish review.
