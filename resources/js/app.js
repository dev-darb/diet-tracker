import { detectBarcode } from './barcode';

// Exposed for the Scan flow's inline Alpine handler (resources/views/livewire/scan.blade.php).
// It reads the captured image on-device and, when a barcode is found, feeds it
// to the Livewire component so resolution takes the keyless barcode -> Open Food
// Facts fast path (BUILD_PLAN idea #1, §7.15).
window.detectBarcode = detectBarcode;

/**
 * Downscale + re-encode a captured photo to JPEG entirely in the browser BEFORE
 * it is uploaded. Phone cameras produce multi-megabyte images (and sometimes
 * HEIC) that can exceed the server's upload/post limit — the upload then fails
 * silently and the Scan flow strands the user with no "Identify" button. Shrinking
 * to a sane max dimension keeps the upload tiny and reliable, normalises HEIC to
 * JPEG, and speeds up both barcode detection and any AI call.
 *
 * Fully defensive: if decoding fails (unsupported format) or anything throws, it
 * returns the ORIGINAL file so the flow still proceeds.
 *
 * @param {File} file the captured image.
 * @param {number} maxDim longest-edge cap in px.
 * @param {number} quality JPEG quality 0..1.
 * @returns {Promise<File>} a smaller JPEG File, or the original on any failure.
 */
window.downscaleImage = async function (file, maxDim = 1600, quality = 0.82) {
    try {
        if (!file || !file.type || !file.type.startsWith('image/')) {
            return file;
        }

        const bitmap = await createImageBitmap(file);
        const longest = Math.max(bitmap.width, bitmap.height);
        const scale = Math.min(1, maxDim / longest);

        // Already small enough — don't bother re-encoding.
        if (scale === 1 && file.size < 1_500_000) {
            bitmap.close?.();
            return file;
        }

        const width = Math.round(bitmap.width * scale);
        const height = Math.round(bitmap.height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
        bitmap.close?.();

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) {
            return file;
        }

        return new File([blob], 'scan.jpg', { type: 'image/jpeg' });
    } catch (_) {
        return file;
    }
};
