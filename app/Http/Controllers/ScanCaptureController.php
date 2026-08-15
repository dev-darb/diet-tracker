<?php

namespace App\Http\Controllers;

use App\Services\ScanCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The scanner's capture drop-box. Each shutter press / barcode read POSTs here
 * independently of the Livewire component, so any number of captures can be in
 * flight at once — the shutter re-arms the instant the previous frame is
 * handed off ("the interface should never make the user wait for the AI").
 * The response is just the capture id; results arrive via the scan screen's
 * polling of scan_captures rows.
 */
class ScanCaptureController extends Controller
{
    public function store(Request $request, ScanCaptureService $service): JsonResponse
    {
        $data = $request->validate([
            // Downscaled to JPEG on-device before upload; the ceiling is
            // generous only for the rare original-file fallback.
            'photo' => ['nullable', 'image', 'max:12288'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'eat_now' => ['nullable', 'boolean'],
        ]);

        $barcode = isset($data['barcode']) && trim($data['barcode']) !== '' ? trim($data['barcode']) : null;

        if ($request->file('photo') === null && $barcode === null) {
            throw ValidationException::withMessages([
                'photo' => 'A capture needs a photo or a barcode.',
            ]);
        }

        $imagePath = null;

        if ($request->file('photo') !== null) {
            try {
                $imagePath = $request->file('photo')->store('scans', 'public');
            } catch (Throwable $e) {
                // Best effort — a storage hiccup must never sink a barcode scan.
                report($e);

                if ($barcode === null) {
                    return response()->json(['message' => 'Could not store the photo — try again.'], 503);
                }
            }
        }

        $capture = $service->queue(
            $request->user(),
            $imagePath,
            $barcode,
            (bool) ($data['eat_now'] ?? false),
        );

        return response()->json(['id' => $capture->id], 201);
    }
}
