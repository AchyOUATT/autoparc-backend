<?php

return [
    // Local private files are suitable for development. Production must opt
    // into a durable private disk; an absent setting deliberately fails closed.
    'documents_disk' => env('EQUIPMENT_DOCUMENTS_DISK', env('APP_ENV', 'production') === 'production' ? null : 'local'),
];
