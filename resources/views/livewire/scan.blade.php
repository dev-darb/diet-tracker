<?php

use App\Models\ScanCapture;
use App\Services\ScanCaptureService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Scan — the pipelined scanner (product principle 7: the interface never makes
 * the user wait for the AI).
 *
 * The camera is the app's own (getUserMedia viewfinder; the OS file input is
 * the graceful fallback). Every shutter press / live barcode read POSTs to
 * ScanCaptureController and the shutter RE-ARMS INSTANTLY — identification and
 * resolution run in a queued job against the durable scan_captures row, and
 * the results stack below the viewfinder fills in as each capture settles.
 * Refresh, navigate away, come back: the rows are still here.
 *
 * The provenance gate lives in ScanCaptureService: identity-grade matches
 * (barcode/exact at 1.0, fuzzy ≥ 0.85) auto-add one unit with UNDO on the
 * card; the 0.60–0.85 suggestion band keeps its blocking confirm — that is
 * the one place a question genuinely protects the data.
 *
 * THIN (brief §4.1): this component renders capture rows and forwards the four
 * card actions to the service. It never touches AI types, resolution logic, or
 * ledger arithmetic.
 */
new #[Layout('components.layouts.app', ['title' => 'Scan'])] class extends Component
{
    /** "Yes, add it" on a suggestion-band card. */
    public function confirmCapture(ScanCaptureService $captures, int $id): void
    {
        $captures->confirm($this->owned($id));
    }

    /** "Not this" on a suggestion-band card — recorded as correction evidence. */
    public function rejectCapture(ScanCaptureService $captures, int $id): void
    {
        $captures->reject($this->owned($id));
    }

    /** Undo an applied capture (reverses the meal and the stocked unit). */
    public function undoCapture(ScanCaptureService $captures, int $id): void
    {
        $captures->undo($this->owned($id));
    }

    /** "…and I'm eating it now" on an applied capture. */
    public function eatNowCapture(ScanCaptureService $captures, int $id): void
    {
        $captures->eatNow($this->owned($id));
    }

    private function owned(int $id): ScanCapture
    {
        return ScanCapture::where('user_id', Auth::id())->findOrFail($id);
    }

    public function with(): array
    {
        // The working session: recent captures, newest first. A 12-hour window
        // means a refresh or interruption never loses settled background work.
        $captures = ScanCapture::with('matchedProduct')
            ->where('user_id', Auth::id())
            ->where('created_at', '>=', now()->subHours(12))
            ->latest()
            ->limit(12)
            ->get();

        return [
            'captures' => $captures,
            'hasInFlight' => $captures->contains(fn (ScanCapture $c) => $c->status->inFlight()),
            'sessionAdds' => $captures->filter(fn (ScanCapture $c) => $c->status->applied())->count(),
        ];
    }
}; ?>

    <div class="space-y-3" @if ($hasInFlight) wire:poll.2s @endif
         x-data="{
            mode: 'booting',            // booting | camera | fallback
            stream: null,
            torchFlash: false,
            shutterArmed: true,
            sending: [],                // client-side captures still uploading
            seenCodes: {},              // live-read barcodes, deduped for 20s
            statusLine: '',
            fb: { preview: null, reading: false, sending: false, error: null },

            async init() {
                // The camera dies with the page: wire:navigate swaps the body,
                // Alpine calls destroy(), and the lamp goes off.
                document.addEventListener('livewire:navigating', () => this.releaseCamera(), { once: true });

                if (!navigator.mediaDevices?.getUserMedia) { this.mode = 'fallback'; return; }
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment', width: { ideal: 1600 } }, audio: false,
                    });
                    this.$refs.video.srcObject = this.stream;
                    this.mode = 'camera';
                    this.armLiveBarcodeReader();
                } catch (_) {
                    this.mode = 'fallback'; // denied / unavailable — the file input path
                }
            },

            destroy() { this.releaseCamera(); },
            releaseCamera() {
                if (this.liveReader) { clearInterval(this.liveReader); this.liveReader = null; }
                if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
            },

            // Live barcode reading straight off the video — no shutter needed.
            armLiveBarcodeReader() {
                if (!('BarcodeDetector' in window)) { return; }
                let detector;
                try { detector = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'] }); }
                catch (_) { return; }
                this.liveReader = setInterval(async () => {
                    if (this.mode !== 'camera' || !this.$refs.video?.videoWidth) { return; }
                    try {
                        const codes = await detector.detect(this.$refs.video);
                        const raw = codes?.[0]?.rawValue?.replace(/[^0-9A-Za-z]/g, '');
                        if (!raw || raw.length < 6) { return; }
                        const now = Date.now();
                        if (this.seenCodes[raw] && now - this.seenCodes[raw] < 20000) { return; }
                        this.seenCodes[raw] = now;
                        this.flash();
                        this.statusLine = 'BARCODE ' + raw + ' — CAPTURED';
                        this.post({ barcode: raw });
                    } catch (_) {}
                }, 700);
            },

            // The shutter: grab the frame, hand it off, re-arm immediately.
            async shutter() {
                if (this.mode !== 'camera' || !this.shutterArmed) { return; }
                const video = this.$refs.video;
                if (!video?.videoWidth) { return; }
                this.shutterArmed = false;
                setTimeout(() => { this.shutterArmed = true; }, 350); // debounce, not a wait
                this.flash();

                const scale = Math.min(1, 1400 / Math.max(video.videoWidth, video.videoHeight));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(video.videoWidth * scale);
                canvas.height = Math.round(video.videoHeight * scale);
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

                canvas.toBlob(async (blob) => {
                    if (!blob) { return; }
                    const job = { key: Date.now() + Math.random(), thumb: URL.createObjectURL(blob), failed: false };
                    this.sending.push(job);

                    // Still frames can carry a barcode the live reader missed
                    // (or the browser has no live reader at all) — a quick
                    // bounded read keeps the keyless fast path on every device.
                    let barcode = null;
                    if (window.detectBarcode) {
                        try {
                            barcode = await Promise.race([
                                window.detectBarcode(blob),
                                new Promise((r) => setTimeout(() => r(null), 2500)),
                            ]);
                        } catch (_) {}
                    }

                    await this.post({ photo: blob, barcode }, job);
                }, 'image/jpeg', 0.8);
            },

            // One capture = one independent POST. The Livewire component only
            // ever polls results, so any number can be in flight at once.
            async post(payload, job = null) {
                const form = new FormData();
                if (payload.photo) { form.append('photo', payload.photo, 'scan.jpg'); }
                if (payload.barcode) { form.append('barcode', payload.barcode); }
                try {
                    const res = await fetch('{{ route('scan.captures.store') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                        body: form,
                    });
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    if (job) { this.sending = this.sending.filter((j) => j.key !== job.key); }
                    this.$wire.$refresh();
                } catch (_) {
                    if (job) { job.failed = true; } else { this.statusLine = 'CAPTURE FAILED — CHECK CONNECTION'; }
                }
            },

            flash() {
                this.torchFlash = true;
                setTimeout(() => { this.torchFlash = false; }, 120);
            },

            // Fallback path: OS camera file input → same drop-box.
            async handleFile(event) {
                const file = event.target.files[0];
                event.target.value = '';
                if (!file) { return; }
                this.fb.error = null;
                this.fb.preview = URL.createObjectURL(file);

                this.fb.reading = true;
                let barcode = null;
                if (window.detectBarcode) {
                    try {
                        barcode = await Promise.race([
                            window.detectBarcode(file),
                            new Promise((r) => setTimeout(() => r(null), 4000)),
                        ]);
                    } catch (_) {}
                }
                this.fb.reading = false;

                this.fb.sending = true;
                const upload = window.downscaleImage ? await window.downscaleImage(file) : file;
                const form = new FormData();
                form.append('photo', upload, 'scan.jpg');
                if (barcode) { form.append('barcode', barcode); }
                try {
                    const res = await fetch('{{ route('scan.captures.store') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                        body: form,
                    });
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    this.fb.preview = null;
                    this.$wire.$refresh();
                } catch (_) {
                    this.fb.error = 'Could not hand the photo off — check the connection and try again.';
                }
                this.fb.sending = false;
            },
         }">

        <h1 class="sr-only">Scan</h1>

        {{-- THE VIEWFINDER — the app's own camera in a recessed bay. ----------}}
        <div class="module px-5 pb-0 pt-4">
            <div class="flex items-baseline justify-between">
                <span class="silkscreen">Scan</span>
                <span class="data-sm text-ink-faint" x-show="mode === 'camera'" x-cloak>LIVE</span>
            </div>

            <div class="well relative mt-3 min-h-56 overflow-hidden">
                {{-- Own camera --}}
                <video x-ref="video" x-show="mode === 'camera'" autoplay playsinline muted
                       class="block h-64 w-full object-cover"></video>

                {{-- Shutter flash --}}
                <div x-show="torchFlash" class="pointer-events-none absolute inset-0 z-10 bg-phosphor/60" aria-hidden="true"></div>

                {{-- Viewfinder corner brackets --}}
                <svg class="pointer-events-none absolute inset-2 z-10 text-phosphor/70" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 8V0h4M96 0h4v8M100 92v8h-4M4 100H0v-8" fill="none" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke"/>
                </svg>

                {{-- Booting --}}
                <div x-show="mode === 'booting'" class="flex min-h-56 flex-col items-center justify-center px-6 text-center">
                    <p class="silkscreen">Starting camera…</p>
                </div>

                {{-- Fallback: the OS camera as a capture well --}}
                <label x-show="mode === 'fallback'" x-cloak class="flex min-h-56 cursor-pointer flex-col items-center justify-center px-6 py-10 text-center">
                    <template x-if="fb.preview">
                        <img :src="fb.preview" alt="Captured product" class="mb-4 max-h-40 rounded object-contain">
                    </template>
                    <template x-if="!fb.preview">
                        <svg class="mb-4 size-9 text-ink-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
                            <path d="M6.5 12h0.01M9.5 12h0.01M12.5 12h0.01M15.5 12h0.01M18 12h-0.01" stroke-width="2" />
                        </svg>
                    </template>
                    <p class="voice-caption font-medium text-ink" x-text="fb.sending ? 'Handing off…' : (fb.reading ? 'Reading barcode…' : 'Take a photo')"></p>
                    <p class="voice-caption mt-1 text-ink-dim">One packaged product at a time — show the front of the pack.</p>
                    <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="handleFile($event)">
                </label>
            </div>

            {{-- The shutter: press, and press again — analysis never holds it. --}}
            <div x-show="mode === 'camera'" x-cloak class="-mx-5 mt-4 border-t border-seam px-5 py-4">
                <button type="button" x-on:click="shutter()"
                        class="key key-action keycap mx-auto flex h-16 w-full max-w-xs items-center justify-center">
                    Capture
                </button>
                <p class="voice-micro mt-2.5 text-center text-ink-dim">
                    Keep going — each shot analyses in the background. Barcodes read themselves.
                </p>
            </div>

            <p class="data-sm -mx-5 border-t border-seam px-5 py-3 text-ink-faint uppercase" x-show="mode !== 'fallback'">
                Live barcode reader · Open Food Facts DB
            </p>
            <p class="data-sm -mx-5 border-t border-seam px-5 py-3 text-ink-faint uppercase" x-show="mode === 'fallback'" x-cloak>
                On-device barcode reader · Open Food Facts DB
            </p>
        </div>

        {{-- Live status line (barcode reads, capture errors) --}}
        <p x-show="statusLine" x-cloak x-text="statusLine" class="data px-1 text-xs text-ink-dim" role="status"></p>
        <p x-show="fb.error" x-cloak x-text="fb.error" class="border-l border-high bg-plate-well px-3 py-2 text-xs leading-relaxed text-ink-dim"></p>

        {{-- THE RESULTS STACK — captures settle here while the shutter stays live. --}}
        @if ($sessionAdds > 0)
            {{-- The session counter re-stamps each time it climbs (keyed on the
                 count) — the loop's running score, landing like a press. --}}
            <div class="flex items-center gap-2 px-1">
                <span class="chip stamp-in border-good/40 text-good" wire:key="session-adds-{{ $sessionAdds }}">+{{ $sessionAdds }} {{ $sessionAdds === 1 ? 'ITEM' : 'ITEMS' }}</span>
                <span class="data-sm text-ink-faint">THIS SESSION</span>
            </div>
        @endif

        {{-- Client-side: frames still being handed off --}}
        <template x-for="job in sending" :key="job.key">
            <div class="module slot-in flex items-center gap-3 px-4 py-3">
                <img :src="job.thumb" alt="" class="size-10 shrink-0 rounded object-cover">
                <div class="min-w-0 flex-1">
                    <p class="data-sm text-ink" x-text="job.failed ? 'HAND-OFF FAILED' : 'HANDING OFF…'"></p>
                    <p class="voice-micro mt-0.5 text-ink-dim" x-text="job.failed ? 'Check the connection, then scan it again.' : 'The shutter is already free.'"></p>
                </div>
                <button type="button" x-show="job.failed" x-on:click="sending = sending.filter((j) => j.key !== job.key)"
                        class="keycap-sm hit shrink-0 text-ink-faint">Dismiss</button>
            </div>
        </template>

        @foreach ($captures as $capture)
            @php($product = $capture->matchedProduct)
            {{-- In flight, the scanline wipes the card (work is happening);
                 the moment it settles, the result wipes in once — but only a
                 FRESH settle earns the wipe, so a reload renders the stack calm. --}}
            @php($justSettled = ! $capture->status->inFlight() && $capture->updated_at->gt(now()->subSeconds(8)))
            <div class="module slot-in px-4 py-3 {{ $capture->status->inFlight() ? 'wipe-busy' : '' }}" wire:key="capture-{{ $capture->id }}">
                <div class="flex items-start gap-3 {{ $justSettled ? 'wipe-in' : '' }}" wire:key="capture-{{ $capture->id }}-{{ $capture->status->value }}">
                    {{-- Evidence: the frame or the digits --}}
                    @if ($capture->image_path)
                        {{-- Deliberately a relative URL: the public-disk symlink serves
                             /storage/* on whatever host the app answers on, so an
                             APP_URL drift can never break thumbnails. Revisit at M8/S3. --}}
                        <img src="/storage/{{ $capture->image_path }}" alt=""
                             class="size-10 shrink-0 rounded object-cover" onerror="this.style.display='none'">
                    @else
                        <div class="flex size-10 shrink-0 items-center justify-center rounded bg-plate-well">
                            <x-app.icon name="barcode" class="size-5 text-ink-faint" />
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        @if ($capture->status->inFlight())
                            <div class="led-sweep flex w-fit gap-1" aria-hidden="true">
                                @for ($i = 0; $i < 6; $i++) <span class="led led-on"></span> @endfor
                            </div>
                            <p class="data-sm mt-1.5 text-ink">IDENTIFYING{{ $capture->barcode ? ' · '.$capture->barcode : '' }}</p>
                            <p class="voice-micro mt-0.5 text-ink-dim">Keep scanning — this settles on its own.</p>

                        @elseif ($capture->status->applied())
                            <p class="voice-caption truncate text-ink">{{ trim(($product->brand ?? '').' '.($product->name ?? '')) ?: 'Item' }}</p>
                            <p class="data-sm mt-0.5 text-ink-faint uppercase">
                                @if ($capture->provenance === 'matched_barcode') Barcode{{ $capture->barcode ? ' '.$capture->barcode : '' }}
                                @elseif ($capture->provenance === 'matched_exact') Database match
                                @else Matched by name · {{ (int) round(((float) $capture->confidence) * 100) }}%
                                @endif
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="chip border-good/40 text-good {{ $justSettled ? 'stamp-in' : '' }}">IN PANTRY</span>
                                @if ($capture->consumption_event_id)
                                    <span class="chip border-good/40 text-good {{ $capture->updated_at->gt(now()->subSeconds(8)) ? 'stamp-in' : '' }}">LOGGED TO TODAY</span>
                                @else
                                    <button type="button" wire:click="eatNowCapture({{ $capture->id }})" wire:loading.attr="disabled"
                                            class="key keycap-sm hit px-3 py-1.5 text-ink-dim">I'm eating it now</button>
                                @endif
                                <button type="button" wire:click="undoCapture({{ $capture->id }})" wire:loading.attr="disabled"
                                        class="keycap-sm hit px-2 py-1.5 text-ink-faint transition hover:text-ink">Undo</button>
                            </div>

                        @elseif ($capture->status === \App\Enums\ScanCaptureStatus::Suggested)
                            <p class="voice-caption truncate text-ink">{{ trim(($product->brand ?? '').' '.($product->name ?? '')) ?: 'Item' }}</p>
                            <p class="data-sm mt-0.5 text-low uppercase">Best guess · {{ (int) round(((float) $capture->confidence) * 100) }}% — is this right?</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="confirmCapture({{ $capture->id }})" wire:loading.attr="disabled"
                                        class="key key-action keycap-sm px-3.5 py-2">Yes, add it</button>
                                <button type="button" wire:click="rejectCapture({{ $capture->id }})" wire:loading.attr="disabled"
                                        class="key keycap-sm px-3.5 py-2 text-ink-dim">Not this</button>
                            </div>

                        @elseif ($capture->status === \App\Enums\ScanCaptureStatus::Unknown)
                            <p class="data-sm text-ink">?--- COULDN'T IDENTIFY THIS YET</p>
                            <p class="voice-micro mt-0.5 text-ink-dim">
                                The photo is kept. <a href="{{ route('pantry') }}" wire:navigate class="text-ink-dim underline decoration-seam-strong underline-offset-2 transition hover:text-ink">Add it manually</a> — a manual add teaches the database.
                            </p>

                        @elseif ($capture->status === \App\Enums\ScanCaptureStatus::AiUnavailable)
                            <p class="data-sm text-low">AI-- PHOTO ID ISN'T SWITCHED ON YET</p>
                            <p class="voice-micro mt-0.5 text-ink-dim">Barcodes still work — they need no AI. Photos will identify once the gateway key is set.</p>

                        @elseif ($capture->status === \App\Enums\ScanCaptureStatus::Failed)
                            <p class="data-sm text-high">ERR IDENTIFICATION FAILED</p>
                            <p class="voice-micro mt-0.5 break-words text-ink-dim">{{ $capture->error ?: 'Something went wrong — scan it again.' }}</p>

                        @elseif ($capture->status === \App\Enums\ScanCaptureStatus::Undone)
                            <p class="data-sm text-ink-faint">UNDONE · {{ trim(($product->brand ?? '').' '.($product->name ?? '')) ?: 'Item' }}</p>

                        @elseif ($capture->status === \App\Enums\ScanCaptureStatus::Rejected)
                            <p class="data-sm text-ink-faint">NOT THIS — NOTED · {{ trim(($product->brand ?? '').' '.($product->name ?? '')) ?: 'Item' }}</p>
                            <p class="voice-micro mt-0.5 text-ink-dim">Corrections make matching sharper.</p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        @if ($captures->isEmpty())
            {{-- Honest blank: what this bay does, before it has done anything. --}}
            <div class="module px-5 py-4">
                <div class="grid grid-cols-3 divide-x divide-seam" aria-hidden="true">
                    <div class="pr-4">
                        <p class="silkscreen">01</p>
                        <p class="voice-micro mt-0.5 text-ink-dim">Scan the pack</p>
                    </div>
                    <div class="px-4">
                        <p class="silkscreen">02</p>
                        <p class="voice-micro mt-0.5 text-ink-dim">It identifies itself</p>
                    </div>
                    <div class="pl-4">
                        <p class="silkscreen">03</p>
                        <p class="voice-micro mt-0.5 text-ink-dim">Stocked &amp; counted</p>
                    </div>
                </div>
            </div>
        @endif

        <p class="px-1 text-center text-xs text-ink-faint">
            Prefer to type it in? <a href="{{ route('pantry') }}" wire:navigate class="text-ink-dim underline decoration-seam-strong underline-offset-4 transition hover:text-ink">Add to pantry manually</a>
        </p>
    </div>
