<?php

return [
    // This module is prepared separately; enabling it is an integration step.
    'enabled' => filter_var(env('PRIVATE_SALES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
