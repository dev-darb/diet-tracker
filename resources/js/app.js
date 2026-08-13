import { detectBarcode } from './barcode';

// Exposed for the Scan flow's inline Alpine handler (resources/views/livewire/scan.blade.php).
// It reads the captured image on-device and, when a barcode is found, feeds it
// to the Livewire component so resolution takes the keyless barcode -> Open Food
// Facts fast path (BUILD_PLAN idea #1, §7.15).
window.detectBarcode = detectBarcode;
