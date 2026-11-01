<?php

return [
    // Render's application filesystem is ephemeral. Source evidence belongs in
    // the private application database, never the public media bucket.
    'archive_store' => env('CONSUMPTION_SOURCE_ARCHIVE_STORE', 'database'),

    // Keep storage plus read-back of incompressible bytes within production's
    // 192 MiB PHP budget, and bound decompression of corrupt size headers.
    'archive_max_bytes' => 16 * 1024 * 1024,
];
