---
name: Jabba
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
  ink-faint: "#7a7e84"
  action: "#ff4d00"
  good: "#2fd05e"
  low: "#ffb020"
  high: "#ff3b30"
  info: "#39c2e8"
typography:
  readout:
    fontFamily: "'DSEG7 Classic', 'Fragment Mono', monospace"
    fontSize: "clamp(4.2rem, 17vw, 5.4rem)"
    fontWeight: 400
    lineHeight: 1
  title:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 500
    lineHeight: 1.375
  body:
    fontFamily: "'Archivo', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  data:
    fontFamily: "'Fragment Mono', ui-monospace, 'SF Mono', monospace"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.4
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
  gap: "12px"
  pad: "16px"
  pad-wide: "20px"
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
  input-well:
    backgroundColor: "{colors.plate-well}"
    textColor: "{colors.ink}"
    rounded: "{rounded.well}"
    padding: "10px 12px"
---

# Design System: Jabba

## Overview

**Creative North Star: "The Kitchen Instrument Console"**

Jabba's app surface is a piece of dark tabletop hardware, not a wellness feed. Every screen is a matte near-black chassis carrying seam-separated module plates; every module silkscreens its own uppercase mono micro-label in the top-left, the way a synthesizer labels a knob. Numbers are instrument readings — tabular mono everywhere, and one seven-segment master readout for the day's kilocalories. Color is functional signal, never mood: five fixed-meaning lamps on an otherwise achromatic machine.

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
- **Over Red** (`high`, #ff3b30): over target / error. HIGH chips, error readouts (`ERR`), validation text.
- **Info Cyan** (`info`, #39c2e8): neutral information states (e.g. "correction recorded").

### Neutral
- **Chassis** (#0a0b0c): the page ground; also the translucent backdrop-blurred bars (`bg-chassis/95`).
- **Plate** (#141517): the standard module surface. **Plate Raised** (#1a1c1f): pressable keys. **Plate Well** (#101113): recessed inputs, capture wells, unlit LED cells.
- **Seam** (#26282c): the universal hairline — module borders, dividers, meter tracks, idle scale ticks. **Seam Strong** (#33363b): emphasized seams, viewfinder brackets, unknown-chip borders, active ticks, scrollbar thumb.
- **Ink** (#eeeff0) / **Ink Dim** (#a2a5aa) / **Ink Faint** (#7a7e84): the three-step text ramp — primary content, secondary/units, silkscreen labels and provenance lines.

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
- **Readout** (400, clamp(4.2rem, 17vw, 5.4rem), line-height 1): DSEG7, used for exactly one element in the app — the TODAY kcal master readout, tinted warm phosphor (#f2ede2) when live, ink-faint when idle (`----`).
- **Title** (500, 1.125rem, snug): Archivo medium; product names, state headings inside modules.
- **Body** (400, 0.875rem): Archivo; spoken sentences in ink-dim, never for numbers.
- **Data** (400, tabular-nums, 0.6875–2.25rem as context demands): Fragment Mono; all values, units (small, ink-dim), provenance lines (11px, 0.06–0.08em tracking, uppercase, ink-faint).
- **Label / Silkscreen** (400, 0.6875rem, 0.14em tracking, UPPERCASE, ink-faint): the module micro-label, key captions, nav labels.

### Named Rules
**The Measured/Spoken Rule.** If it's measured, it's mono; if it's spoken, it's grotesk. No number ever renders in Archivo; no sentence ever renders in Fragment Mono.

**The One Readout Rule.** DSEG7 appears exactly once in the app: the Home kcal master readout. A second seven-segment element anywhere devalues the gauge.

## Layout

A mobile-first single column: `max-w-md` centered on the chassis, full-height flex shell. Sticky top status bar (user's name + live dot + date, seam-bottomed, `bg-chassis/95` + backdrop-blur) and a fixed bottom control strip with safe-area-inset padding; main content gets `px-4 pt-4 pb-32` clearance. Modules stack with a constant 12px rhythm (`space-y-3`); inside a module the padding is 16px top / 20px sides and bottom. Multi-value modules divide internally with seam hairlines (`divide-x` / `divide-y`) rather than nesting cards. Density is instrument-panel tight: rows at ~10px vertical padding, silkscreen label flush top-left.

## Elevation & Depth

No ambient shadows anywhere. Depth is conveyed by material tone (well → plate → raised) and by 1px seams; the chassis shows between plates as physical gap. The single shadow in the system is structural, not atmospheric: the primary action key's machined edge (`box-shadow: 0 2px 0 0 #a33200`), a hardware ledge that collapses to 0 on `:active` as the key physically depresses (`translateY(2px)`). This is the world's native pressed-key device, not a decorative offset shadow — do not generalize it to non-key surfaces.

### Named Rules
**The Seam Rule.** Separation is always a 1px seam or a tonal step, never a drop shadow, glow, or blur (backdrop-blur on the chassis bars is the sole blur, and it blurs content, not edges).

## Shapes

Machined, small-radius geometry: 6px on plates and keys, 5px on recessed wells, 4px on chips, 2px on LED cells and meter tracks. Nothing is pill-shaped or fully round except two tiny status dots (live dot, barcode lamp). Corners never exceed 6px. Recurring silhouettes: the seam-bordered plate, the raised key, the 10px square LED cell, the 3px meter strip, viewfinder corner brackets drawn as stroked SVG paths. Charts are quantized — sparkline bars render as stacked LED cells, the kcal scale as engraved tick marks — never smooth gradient fills.

## Components

### Keys (buttons)
- **Character:** physical console keys — they depress, they don't hover-glow.
- **Shape:** 6px radius; full-width action keys at `py-3.5`, square utility keys at fixed sizes (size-8 to size-12).
- **Default key:** plate-raised fill, seam border, ink or ink-dim caption; `:active` → `translateY(1px)` + `brightness(1.15)` over 60–120ms ease-out.
- **Action key** (`key-action`): solid signal orange, black caption, machined lower edge (`0 2px 0 0 #a33200`); `:active` → sinks 2px, ledge collapses. Captions are Fragment Mono, 0.14em tracking, uppercase.
- **Secondary actions** use the default key with ink-dim captions — never a second colored key on the same screen.
- **Focus:** global `:focus-visible` — 2px signal-orange outline, 2px offset.

### Chips
- **Style:** Fragment Mono 11px, 0.08em tracking, uppercase, 4px radius, `0.2rem 0.55rem` padding.
- **Band variant:** solid signal fill + black text per the One-Fill Band Grammar; unknown = seam-strong border, ink-faint, `NO DATA`.
- **Counter variant** (reward strip): bordered, transparent fill — orange border/text for the moved counter (`+1 ITEM`), seam-strong border + ink-dim for totals.

### Modules (cards)
- **Corner Style:** 6px. **Background:** plate. **Border:** 1px seam. **Shadow:** none (see Elevation).
- **Internal padding:** 16px top, 20px sides/bottom; 12px stack gap between modules.
- **Self-labeling:** every module opens with a silkscreen micro-label (`TODAY`, `STREAK`, `INDICATORS`); no module ships unlabeled.

### Inputs / Fields
- **Style:** recessed wells — plate-well fill, 1px seam border, 5px radius, data-voice text.
- **Focus:** border shifts to signal orange (`focus:border-action`), no glow.
- **Error:** message in `high` red, 12px; inline warnings render as a plate-well strip with a 1px left border in the relevant signal color.
- **Capture well:** a viewfinder, not a form — min-height well with seam-strong SVG corner brackets, hover strengthens the seam.

### Navigation
- **Control strip:** fixed bottom, 5-key grid on a seam-topped `bg-chassis/95` blurred plate. Four flat keys (h-14, silkscreen captions; active = ink caption + `aria-current`), and the raised orange SCAN key: taller (h-[4.25rem]), pulled up 12px above the row, stroke-icon + mono caption in black.
- **Icons** throughout are inline stroked SVGs (1.6–3.2 stroke, round caps), currentColor — no icon fonts, no filled glyph sets.

### The Reward Stamp (signature)
The earned-success moment, in hardware grammar: a full-bleed green field (6px radius, black ink) that stamps in (`stamp-in`: 160ms `cubic-bezier(0.16,1,0.3,1)`, scale 1.06→1), carrying an oversized stroked check, a bold uppercase Archivo declaration ("ADDED TO PANTRY"), and a 16-cell LED sweep (per-cell 90ms ease-out fade, 40ms stagger, left to right). Below it: the fact module (what was added, its macros, its provenance) and the reward strip of counter chips that just moved. Fires only on a genuine completion trigger.

**The Crisp Motion Rule.** All motion is short (60–160ms base) exponential ease-out — never springy, bouncy, or looping. The LED sweep and the stamp are the only authored moments; both die under `prefers-reduced-motion`.

**The Honest Blank Rule.** An unknown value renders as `----` (or `—`, an unlit LED, an empty seam-colored track) in ink-faint. The machine never draws a zero, a guessed bar, or a placeholder number for data it doesn't hold.

## Do's and Don'ts

### Do:
- **Do** open every module with a silkscreen micro-label (Fragment Mono 11px, 0.14em, uppercase, ink-faint) at top-left; modules self-identify.
- **Do** set every measured value in Fragment Mono with `tabular-nums`, with units small and ink-dim beside the number, and provenance in an 11px uppercase mono line.
- **Do** separate with 1px seams and tonal steps (well/plate/raised); keep the 12px module rhythm.
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
