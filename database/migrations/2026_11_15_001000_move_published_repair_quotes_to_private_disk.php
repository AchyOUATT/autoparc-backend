<?php

use App\Support\FaultRepairQuoteStorage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        FaultRepairQuoteStorage::reclaimPublishedCopies();
    }

    public function down(): void
    {
        // Les copies restent sur le disque privé ; un retour en arrière
        // ne doit pas les exposer à nouveau sur un stockage public.
    }
};
