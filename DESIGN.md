---
name: foody
description: A personal food intelligence console — every number an instrument readout, every fact carrying provenance.
colors:
  chassis: "#0a0b0c"
  plate: "#141517"
  plate-raised: "#1a1c1f"
  plate-well: "#101113"
  seam: "#26282c"
  seam-strong: "#33363b"
  ink: "#eeeff0"
  ink-dim: "#a2a5aa"
  ink-faint: "#7e838b"
  action: "#ff4d00"
  good: "#2fd05e"
  low: "#ffb020"
  high: "#ff3b30"
  info: "#39c2e8"
  phosphor: "#f2ede2"
typography:
  readout:
    fontFamily: "'DSEG7 Classic', 'Fragment Mono', monospace"
    fontSize: "clamp(4.2rem, 17vw, 5.4rem)"
    fontWeight: 400
    lineHeight: 1
  voice-display:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "2.6rem"
    fontWeight: 700
    letterSpacing: "0.01em"
    lineHeight: 0.95
  voice-title:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 550
    letterSpacing: "-0.01em"
    lineHeight: 1.25
  voice-item:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 500
    lineHeight: 1.35
  voice-body:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 400
    lineHeight: 1.6
  voice-caption:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  voice-micro:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: 1.45
  data-xl:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "2.25rem"
    fontWeight: 400
    lineHeight: 1.1
  data-lg:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "1.25rem"
    fontWeight: 400
    lineHeight: 1.2
  data-md:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.45
  data-sm:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.75rem"
    fontWeight: 400
    letterSpacing: "0.02em"
    lineHeight: 1.5
  data-micro:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.625rem"
    fontWeight: 400
    letterSpacing: "0.02em"
    lineHeight: 1.4
  station-caption:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.5625rem"
    fontWeight: 400
    letterSpacing: "0.08em"
    lineHeight: 1
  keycap:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.8125rem"
    fontWeight: 400
    letterSpacing: "0.12em"
  keycap-sm:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.6875rem"
    fontWeight: 400
    letterSpacing: "0.12em"
  label:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.6875rem"
    fontWeight: 400
    letterSpacing: "0.14em"
rounded:
  cell: "2px"
  chip: "4px"
  well: "5px"
  plate: "6px"
spacing:
  gap-pair: "8px"
  gap: "12px"
  pad: "16px"
  pad-wide: "20px"
  gap-band: "20px"
components:
  module:
    backgroundColor: "{colors.plate}"
    rounded: "{rounded.plate}"
    padding: "16px 20px 20px"
  key:
    backgroundColor: "{colors.plate-raised}"
    textColor: "{colors.ink}"
    rounded: "{rounded.plate}"
  key-action:
    backgroundColor: "{colors.action}"
    textColor: "#000000"
    rounded: "{rounded.plate}"
    padding: "14px 16px"
  chip:
    typography: "{typography.label}"
    rounded: "{rounded.chip}"
    padding: "3.2px 8.8px"
  well:
    backgroundColor: "{colors.plate-well}"
    rounded: "{rounded.well}"
  input-well:
    backgroundColor: "{colors.plate-well}"
    textColor: "{colors.ink}"
    rounded: "{rounded.well}"
    padding: "10px 12px"
---

# Design System: foody

> **Rename (Aug 2026):** the product is now **foody**, home `foody.gg` — the
> design world previously codenamed "Jabba" is unchanged; only the name and the
> status-bar wordmark (FOODY silkscreen) moved.

## Overview

**Creative North Star: "The Kitchen Instrument Console"**

foody's app surface is a piece of dark tabletop hardware, not a wellness feed. Every screen is a matte near-black chassis carrying seam-separated module plates; every module silkscreens its own uppercase mono micro-label in the top-left, the way a synthesizer labels a knob. Numbers are instrument readings — tabular mono everywhere, and one seven-segment master readout for the day's kilocalories. Color is functional signal, never mood: five fixed-meaning lamps on an otherwise achromatic machine.

The console is the user's, not the brand's: the status bar carries the user's name, and the machine reports only what it actually measured. When the machine doesn't know, it says so — `----`, `NO DATA`, an unlit LED, an empty meter track — never a fabricated zero. When the user genuinely completes the loop, the reward fires as hardware: a full green field, an LED sweep, a stamped check, counters that tick. Nothing celebratory happens without a real trigger.

This is one committed dark world. There is no light mode and no appearance toggle; `html.dark` is hard-set and the browser chrome (selection, caret, scrollbar, focus ring) is tuned to the console.

**Key Characteristics:**
- Matte near-black chassis; plates separated by 1px hairline seams; no ambient shadows.
- Every module self-labels with a silkscreen mono micro-label.
- If it's measured, it's mono; if it's spoken, it's grotesk.
- Five fixed-meaning signal colors on small areas; the machine is otherwise achromatic.
- Honest unknowns: `----` / `NO DATA` / empty tracks, never invented zeros.
- Rewards are hardware events (green field, LED sweep, stamp, ticking counters), earned only.

## Colors

An achromatic three-tone chassis lit by five small, fixed-meaning signal lamps.

### Primary
- **Signal Orange** (`action`, #ff4d00): the machine's one primary-action color. The raised SCAN key, primary action keys, streak LEDs, the kcal scale marker, focus rings, text selection, the caret. It means "act" or "streak alive" — nothing else.

### Secondary — Functional Signals
- **Confirm Green** (`good`, #2fd05e): confirmed / success / in-band. GOOD chips, the barcode-found lamp, and the one sanctioned large field: the success stamp panel.
- **Low Amber** (`low`, #ffb020): under target / caution. LOW chips, warning border-accents.
- **Over Red** (`high`, #ff3b30): over target / error. HIGH chips, error readouts (`ERR`), validation text, the offline link lamp's border.
- **Info Cyan** (`info`, #39c2e8): neutral information states (e.g. "correction recorded").

### Neutral
- **Chassis** (#0a0b0c): the page ground; also the translucent backdrop-blurred top bar (`bg-chassis/95`).
- **Plate** (#141517): the standard module surface. **Plate Raised** (#1a1c1f): pressable keys. **Plate Well** (#101113): recessed inputs, capture wells, unlit LED cells.
- **Seam** (#26282c): the universal hairline — module borders, dividers, meter tracks, idle scale ticks. **Seam Strong** (#33363b): emphasized seams, viewfinder brackets, unknown-chip borders, active ticks, scrollbar thumb.
- **Ink** (#eeeff0) / **Ink Dim** (#a2a5aa) / **Ink Faint** (#7e838b): the three-step text ramp — primary content, secondary/units, silkscreen labels and provenance lines.

### Named Rules
**The Fixed-Meaning Rule.** The five signals carry fixed semantics (action, good, low, high, info) on small areas only. A signal color is never decorative, never a background wash, and never re-assigned. The single exception is the earned success stamp's full green field.

**The Black-on-Signal Rule.** Text and glyphs sitting on any signal fill are pure black (#000). Signals are lamps; lamps don't carry grey type.

**The One-Fill Band Grammar.** Qualitative band chips share one grammar: solid signal fill + black text (GOOD/OK → green, OK at 70% opacity; LOW → amber; HIGH → red). Unknown bands get no fill — seam-strong border, ink-faint text, label `NO DATA`.

## Typography

**Display Font:** DSEG7 Classic (seven-segment; fallback Fragment Mono)
**Body Font:** Archivo variable (100–900, 62.5–125% stretch; fallback system sans)
**Label/Mono Font:** Fragment Mono (400 only; fallback ui-monospace)

**Character:** A two-voice machine. Archivo is the spoken voice — instructions, sentences, product names. Fragment Mono is the data voice — every measured value, unit, timestamp, and label, always with tabular numerals. DSEG7 is the one physical gauge.

### Hierarchy

Roles are tokenized classes in `resources/css/app.css`; views never set raw sizes or trackings. Three letter-spacings exist in the system, each owned by a device: 0.14em (silkscreen engraving), 0.12em (keycaps), 0.08em (band chips); data roles carry a hairline 0.02em at small sizes.

**Spoken voice (Archivo)** — `voice-*`:
- **voice-display** (700, uppercase, 0.01em, lh 0.95): the stamp voice — the ADDED TO PANTRY banner (2.6rem there); shouting reserved for earned wins.
- **voice-title** (550 variable weight, 1.25rem, −0.01em): screen titles (Pantry, Eat, Health).
- **voice-item** (500, 1.125rem, lh 1.35): entity names — products, insight titles, state headings.
- **voice-body** (400, 0.9375rem, lh 1.6): primary prose — insight bodies, empty-state instructions.
- **voice-caption** (400, 0.875rem, lh 1.5): secondary prose, row labels, subtitles.
- **voice-micro** (400, 0.75rem, lh 1.45): unit captions and fine print under data values.

**Measured voice (Fragment Mono, tabular)** — a five-step gauge ramp, one `data-xl` per screen (the reading the screen exists to give):
- **data-xl** 2.25rem — screen hero value (week average, item quantity, idle `----`).
- **data-lg** 1.25rem — tile/counter values (macros, weekly counters).
- **data-md** 0.875rem — row values, the KCAL unit, the log voice on Eat.
- **data-sm** 0.75rem (0.02em) — metadata: provenance, timestamps, footers.
- **data-micro** 0.625rem (0.02em) — axis endpoints, engraved unit suffixes, sparkline day labels.
- **keycap** 0.8125rem / **keycap-sm** 0.6875rem (both 0.12em, uppercase) — the one engraving standard on every pressable control; below 350px viewports `keycap-sm` trades tracking for fit (0.06em) rather than wrapping.
- **silkscreen** 0.6875rem (0.14em, uppercase, ink-faint) — module micro-labels and nav keys; also the top-bar nameplate's engraving.
- **Readout** (DSEG7, clamp(4.2rem, 17vw, 5.4rem), lh 1): unchanged — the one gauge.

### Named Rules
**The Measured/Spoken Rule.** If it's measured, it's mono; if it's spoken, it's grotesk. No number ever renders in Archivo; no sentence ever renders in Fragment Mono.

**The One Readout Rule.** DSEG7 appears exactly once in the app: the Home **Foody Score** master readout (distill, Aug 2026 — the score is the number the product exists to give; kcal demoted to the screen's `data-xl` mono value). The share card reprints the same reading — one gauge, shown twice, never a second instrument. Any other seven-segment element devalues the gauge.

## Layout

A mobile-first single column: `max-w-md` centered on the chassis (seam-edged with `md:border-x` on wider viewports), full-height flex shell. Sticky top status bar (orange live dot + user's first name + FOODY silkscreen, date, profile key; seam-bottomed, `bg-chassis/95` + backdrop-blur) and a fixed bottom control strip with safe-area-inset padding; main content gets `px-4 pt-4 pb-32` clearance.

**The Faceplate Rule.** Readings that belong to one instrument share one plate, divided internally by seams (`divide-x`/`divide-y`, edge-to-edge via negative margins) — never a stack of adjacent cards. Home's faceplate (distill, Aug 2026) is the canonical example: score → spoken read → TODAY band (kcal `data-xl` + calibrated scale) → macro cluster → LAST 7 DAYS band, all one plate. Health's THIS WEEK cluster (average + sparkline + metric grid + counters row) is the other. One hero cluster per screen.

**The Band Rhythm.** The page stacks in functional bands — MEASURE → ASSESS → MOTIVATE/GUIDE — on a two-step cadence: 8px pairs modules within a band (wrapper `space-y-2` or `-mt-3` against a 20px stack), 20px separates bands (root `space-y-5`); 12px remains the interior content rhythm only. A constant gap between all modules is the card-soup tell this rule exists to prevent.

**The Recessed Bay Rule.** Standby, empty, and not-yet-powered regions render as recessed wells (`bg-plate-well`, seam border, no plate) — an unpowered slot in the face, never a full module pretending to hold content.

**Panel printing — RETIRED (distill, Aug 2026).** Process-step diagrams (01/02/03 rows) and capability engravings are gone: empty states are recessed bays carrying one spoken line, and the machine explains itself by behaving, not by printing its own manual. Capability footers that survive (e.g. the scanner's ON-DEVICE BARCODE READER line) state provenance, never process.

Density is instrument-panel tight: rows at ~10px vertical padding, silkscreen label flush top-left; multi-value regions divide with seam hairlines rather than nesting cards.

## Elevation & Depth

No ambient shadows anywhere. Depth is conveyed by material tone (well → plate → raised) and by 1px seams; the chassis shows between plates as physical gap. The single shadow in the system is structural, not atmospheric: the primary action key's machined edge (`box-shadow: 0 2px 0 0 #a33200`), a hardware ledge that collapses to 0 on `:active` as the key physically depresses (`translateY(2px)`). This is the world's native pressed-key device, not a decorative offset shadow — do not generalize it to non-key surfaces.

### Named Rules
**The Seam Rule.** Separation is always a 1px seam or a tonal step, never a drop shadow, glow, or blur (backdrop-blur on the top status bar is the sole blur, and it blurs content, not edges).

## Shapes

Machined, small-radius geometry: 6px on plates and keys, 5px on recessed wells, 4px on chips, 2px on LED cells and meter tracks. Nothing is pill-shaped or fully round except two tiny status dots (live dot, barcode lamp). Corners never exceed 6px. Recurring silhouettes: the seam-bordered plate, the raised key, the 10px square LED cell, the 3px meter strip, viewfinder corner brackets drawn as stroked SVG paths. Charts are quantized — sparkline bars render as stacked LED cells, the kcal scale as engraved tick marks — never smooth gradient fills.

**Food Imagery Is Functional UI (distill, Aug 2026).** The food itself is interface, never decoration: the capture the phone just shot is the HERO of its scan result card (edge-to-edge, aspect 5/3; compact in shopping mode); pantry rows lead with the product's own OFF photo at row scale (`size-9`, well-backed); a quiet mono monogram in a well stands in where no image exists — never a generic food glyph pretending. Imagery earns its place by identifying, and it degrades to typography, not to broken frames.

**Time Is a Signal (distill, Aug 2026).** Expiry renders as a data value in the row's measured cluster (`data-micro`, uppercase: `5D LEFT` / `USE TODAY` / `PAST BEST`), shown only inside a week of relevance, amber only when imminent (≤1 day). Never an alarm, a badge, or a modal.

## Components

The reusable layer lives in two tiers: CSS classes in `resources/css/app.css` (`.module`, `.key`, `.key-action`, `.chip`, `.led`, `.meter`, `.well`, `.input-well`, the type roles) and Blade components in `resources/views/components/app/`:

- **`<x-app.module label meta padding>`** — the labeled instrument plate (silkscreen label, optional right-side meta readout). Faceplate clusters with bespoke internals stay hand-built.
- **`<x-app.console-key href primary>`** — the full-width stacked action key at the foot of a flow step; renders a button or link, passes wire/Alpine attributes through. Inline and compact keys keep their own markup.
- **`<x-app.placeholder glyph tone status title subtitle>`** — every idle and fault instrument state (`----` empty, `ERR`, `AI--`, `?---`, `LOGD`), tones keyed to the signal palette, optional slot for detail rows.
- **`<x-app.stamp-toast show tone>`** — the reward stamp (green + check, genuine wins only) and the neutral quiet-save plate; announces via `role="status"`.
- **`.input-well`** — one seated treatment for every form field (well material, ink text, action-orange focus, dark color-scheme).
- Existing: `<x-app.indicators>`, `<x-app.sparkline>`, `<x-app.bottom-nav>`, `<x-app.health-disclaimer>`.

### Keys (buttons)
- **Character:** physical console keys — they depress, they don't hover-glow.
- **Shape:** 6px radius; full-width action keys at `py-3.5`, square utility keys at fixed sizes (size-8 to size-12).
- **Default key:** plate-raised fill, seam border, ink or ink-dim caption; `:active` → `translateY(1px)` + `brightness(1.15)` over 60–120ms ease-out.
- **Action key** (`key-action`): solid signal orange, black caption, machined lower edge (`0 2px 0 0 #a33200`); `:active` → sinks 2px, ledge collapses. Captions use the keycap engraving standard (Fragment Mono, 0.12em tracking, uppercase).
- **Secondary actions** use the default key with ink-dim captions — never a second colored key on the same screen.
- **Focus:** global `:focus-visible` — 2px signal-orange outline, 2px offset.

### Chips
- **Style:** Fragment Mono 11px, 0.08em tracking, uppercase, 4px radius, `0.2rem 0.55rem` padding.
- **Band variant:** solid signal fill + black text per the One-Fill Band Grammar; unknown = seam-strong border, ink-faint, `NO DATA`.
- **Counter variant** (reward strip): bordered, transparent fill — orange border/text for the moved counter (`+1 ITEM`), seam-strong border + ink-dim for totals.

### Modules (cards)
- **Corner Style:** 6px. **Background:** plate. **Border:** 1px seam. **Shadow:** none (see Elevation).
- **Internal padding:** 16px top, 20px sides/bottom; stack gaps follow the Band Rhythm (8px within a band, 20px between bands, 12px interior only).
- **Self-labeling:** every module opens with a silkscreen micro-label (`TODAY`, `STREAK`, `INDICATORS`); no module ships unlabeled.

### Inputs / Fields
- **Style:** recessed wells — plate-well fill, 1px seam border, 5px radius, data-voice text.
- **Focus:** border shifts to signal orange (`focus:border-action`), no glow.
- **Error:** message in `high` red, 12px; inline warnings render as a plate-well strip with a 1px left border in the relevant signal color.
- **Capture well:** a viewfinder, not a form — min-height well with seam-strong SVG corner brackets, hover strengthens the seam.

### Navigation
- **Control strip** (redesigned Aug 2026, founder feedback): a raised machined plate (`bg-plate`, 1px `seam-strong` top edge — a material edge, not a hairline afterthought), not a row of outlined boxes. Four engraved stations: glyph (size-5, from the icon bank: house/basket/fork/pulse) over a mono micro-caption (9px, 0.08em tracking); active = ink + a 2px orange indicator tick seated under the plate edge; inactive = ink-faint. Station press is deliberately physical: scale 0.92 + brightness 1.35, 80ms.
- **The SCAN key:** an orange rounded-square (60px, rounded-2xl) punched **through** the plate edge on a 4px chassis ring, floating clear of the seam — never touching it. Icon-only (scan brackets), machined ledge (0 3px 0 #a33200); pressing drops it fully onto the ledge; on the Scan screen it reads latched down (2px seated, dimmed 8%).
- **Icons** throughout are inline stroked SVGs (1.6–3.2 stroke, round caps), currentColor — no icon fonts, no filled glyph sets.

### The Reward Stamp (signature)
The earned-success moment, in hardware grammar: a full-bleed green field (6px radius, black ink) that stamps in (`stamp-in`: 160ms `cubic-bezier(0.16,1,0.3,1)`, scale 1.06→1), carrying an oversized stroked check, a bold uppercase Archivo declaration ("ADDED TO PANTRY"), and a 16-cell LED sweep (per-cell 90ms ease-out fade, 40ms stagger, left to right). Below it: the fact module (what was added, its macros, its provenance) and the reward strip of counter chips that just moved. Fires only on a genuine completion trigger. Smaller wins use `<x-app.stamp-toast>`: a compact stamped strip (green + check for genuine wins, neutral raised plate for quiet saves) that lands with the same `stamp-in` press.

**The Crisp Motion Rule.** All motion draws from three durations (`--motion-micro` 100ms, `--motion-state` 160ms, `--motion-wipe` 420ms) and three curves (`--ease-travel` cubic-bezier(0.2,0,0,1) for mechanical travel, `--ease-arrive` cubic-bezier(0.16,1,0.3,1) for arrivals, `--ease-exit` for departures) — never springy, bouncy, or decoratively looping. Every authored move degrades under `prefers-reduced-motion` to opacity-only or nothing, without losing state feedback.

**The Instant Navigation Rule (motion).** Page-to-page navigation carries **no transition animation**: a fetch-bound body swap cannot honestly imitate native paging, and a transition that races the network shows its seams (founder, Aug 2026). The chassis — top status bar, bottom control strip — is identical across pages, so a swap reads as the plate content changing under fixed hardware. The motion budget is spent inside the page instead.

**The Landed Action Rule (motion).** Every encouraged action answers twice: the **press** (the key depresses — `.key` physics) and the **landing** — visible proof the data moved because of you. A readout whose value just changed glows good and settles (`.value-settle`, 700ms, keyed to the value with a recency gate so page loads render calm); a chip stamps in (`.stamp-in`); a new row seats into its stack (`.slot-in`); a genuine win fires the reward stamp. The landing celebrates the *data* changing, never the screen changing.

**The Wipe Rule (motion).** A wipe is the language of background work, never decoration: `.wipe-busy` tracks a phosphor scanline across anything analysing off-screen, and `.wipe-in` reveals a freshly settled result left-to-right (420ms, once — reloads render the stack calm). Disclosures use `.split` / `.split-open` (grid-row collapse, 160ms): a surface parting to reveal controls, not an element fading into existence. New rows seat with `.slot-in` (6px drop, 160ms). All motion rides transform / opacity / clip-path; plates never animate layout.

**The Honest Blank Rule.** An unknown value renders as `----` (or `—`, an unlit LED, an empty seam-colored track) in ink-faint. The machine never draws a zero, a guessed bar, or a placeholder number for data it doesn't hold.

## Do's and Don'ts

### Do:
- **Do** open every module with a silkscreen micro-label (Fragment Mono 11px, 0.14em, uppercase, ink-faint) at top-left; modules self-identify.
- **Do** set every measured value in Fragment Mono with `tabular-nums`, with units small and ink-dim beside the number, and provenance in an 11px uppercase mono line.
- **Do** separate with 1px seams and tonal steps (well/plate/raised); keep the Band Rhythm (8px pairs, 20px bands, 12px interior).
- **Do** put black type on any signal fill, and keep signal areas small — a chip, an LED, a marker line, a key.
- **Do** render unknowns as `----` / `NO DATA` / unlit cells, and quantize charts into cell stacks and tick scales.
- **Do** respect `prefers-reduced-motion` for every authored animation.

### Don't:
- **Don't** add drop shadows, glows, or gradients; the only shadow is the action key's machined ledge, and it stays on keys.
- **Don't** use signal colors decoratively, as washes, or with shifted meanings; the only large signal field is the earned green success stamp.
- **Don't** fire reward grammar (green field, LED sweep, stamp, ticking counters) without a genuine success trigger, and never use confetti or springy easing.
- **Don't** set a number in Archivo or a sentence in Fragment Mono, and never use DSEG7 outside the single Home kcal readout.
- **Don't** introduce a light mode, an appearance toggle, or pill radii (>6px corners); the world is one committed dark console.
- **Don't** use icon fonts or filled glyph icons; icons are inline stroked SVGs in currentColor.
