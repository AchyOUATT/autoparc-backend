<?php

namespace App\Models\Concerns;

trait HasFaultDeclaration
{
    public function initializeHasFaultDeclaration(): void
    {
        // Complete snapshots support public filtering, management forms and inspection hashes in one batch.
        $this->with[] = 'faults';
    }
}
