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
window.downscaleImage = function (file, maxDim = 1400, quality = 0.8) {
    if (!file || !file.type || !file.type.startsWith('image/')) {
        return Promise.resolve(file);
    }

    // Decode with an <img> element (far more reliable on iOS Safari than
    // createImageBitmap, which can hang indefinitely on some devices).
    const work = new Promise((resolve) => {
        let settled = false;
        const finish = (result, url) => {
            if (settled) {
                return;
            }
            settled = true;
            if (url) {
                URL.revokeObjectURL(url);
            }
            resolve(result);
        };

        let url;
        try {
            url = URL.createObjectURL(file);
        } catch (_) {
            finish(file);
            return;
        }

        const img = new Image();
        img.onload = () => {
            try {
                const longest = Math.max(img.naturalWidth, img.naturalHeight) || maxDim;
                const scale = Math.min(1, maxDim / longest);

                // Already small enough — don't bother re-encoding.
                if (scale === 1 && file.size < 1_200_000) {
                    finish(file, url);
                    return;
                }

                const width = Math.round(img.naturalWidth * scale);
                const height = Math.round(img.naturalHeight * scale);
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                canvas.toBlob(
                    (blob) => finish(blob ? new File([blob], 'scan.jpg', { type: 'image/jpeg' }) : file, url),
                    'image/jpeg',
                    quality,
                );
            } catch (_) {
                finish(file, url);
            }
        };
        img.onerror = () => finish(file, url);
        img.src = url;
    });

    // Hard guard: never let a stuck decode/encode freeze the Scan flow — after a
    // few seconds we just upload the original file instead.
    const guard = new Promise((resolve) => setTimeout(() => resolve(file), 6000));

    return Promise.race([work, guard]);
};
