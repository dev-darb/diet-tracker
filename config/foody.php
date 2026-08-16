<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scan capture storage
    |--------------------------------------------------------------------------
    |
    | Where photos taken by the scanner are kept. The default `public` disk is
    | LOCAL DISK, which on a serverless platform means a capture may not survive
    | the redeploy that follows it (DEPLOY.md Part B). That is tolerable while a
    | capture is only ever work-in-flight: the scanner shows the frame the phone
    | still holds in memory, and the photo's job is done once identification has
    | run.
    |
    | It stops being tolerable the moment a capture becomes part of a food's
    | identity — the plate you actually cooked, shown back to you on the meal
    | later. That needs durable storage, so point this at an object-storage disk
    | and the capture outlives the container:
    |
    |     SCAN_DISK=s3
    |
    | Captures are private user data. Keep the bucket private and serve them
    | through temporary signed URLs; never make the bucket world-readable to
    | save a step.
    |
    */

    'scans' => [
        'disk' => env('SCAN_DISK', env('FILESYSTEM_DISK', 'public')),

        /*
        | How long a signed URL for a capture stays valid. Long enough to open
        | the page and look, short enough that a copied link is not a permanent
        | handle on someone's kitchen.
        */
        'url_ttl_minutes' => (int) env('SCAN_URL_TTL_MINUTES', 30),
    ],

];
