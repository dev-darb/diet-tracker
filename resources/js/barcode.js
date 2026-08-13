/**
 * On-device barcode reading for the Scan flow (BUILD_PLAN idea #1, §7.15).
 *
 * A barcode -> Open Food Facts lookup is free, deterministic and near-instant,
 * so we try to read a barcode from the captured image IN THE BROWSER before
 * spending an AI call on visual identification. Two strategies, best first:
 *
 *   1. The native `BarcodeDetector` API (Chromium, Android WebView, and an
 *      increasing share of Safari). Fast, no download.
 *   2. A bundled `@zxing/browser` fallback, imported lazily so it is only
 *      fetched on browsers that lack `BarcodeDetector`.
 *
 * Both are wrapped so ANY failure (unsupported API, no barcode in frame, decode
 * error) resolves to `null` rather than throwing — the Scan flow then simply
 * falls back to photo -> AI identification. This degrades gracefully on every
 * browser; nothing here is required for the app to work.
 *
 * @param {Blob|File} source the captured image.
 * @returns {Promise<string|null>} the barcode digits, or null if none read.
 */
export async function detectBarcode(source) {
    if (!source) {
        return null;
    }

    const native = await tryNativeDetector(source);
    if (native) {
        return native;
    }

    return tryZxing(source);
}

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'];

async function tryNativeDetector(source) {
    if (typeof window === 'undefined' || !('BarcodeDetector' in window)) {
        return null;
    }

    try {
        let formats = FORMATS;
        if (typeof window.BarcodeDetector.getSupportedFormats === 'function') {
            const supported = await window.BarcodeDetector.getSupportedFormats();
            formats = FORMATS.filter((f) => supported.includes(f));
        }

        const detector = new window.BarcodeDetector(formats.length ? { formats } : undefined);
        const bitmap = await createImageBitmap(source);

        try {
            const codes = await detector.detect(bitmap);
            return normalise(codes && codes.length ? codes[0].rawValue : null);
        } finally {
            if (typeof bitmap.close === 'function') {
                bitmap.close();
            }
        }
    } catch (_) {
        return null;
    }
}

async function tryZxing(source) {
    let url;

    try {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        const reader = new BrowserMultiFormatReader();
        url = URL.createObjectURL(source);
        const result = await reader.decodeFromImageUrl(url);

        return normalise(result && typeof result.getText === 'function' ? result.getText() : null);
    } catch (_) {
        return null;
    } finally {
        if (url) {
            URL.revokeObjectURL(url);
        }
    }
}

/** Keep only digits and reject anything too short to be a real GTIN. */
function normalise(value) {
    if (!value) {
        return null;
    }

    const digits = String(value).replace(/\D/g, '');

    return digits.length >= 8 ? digits : null;
}
