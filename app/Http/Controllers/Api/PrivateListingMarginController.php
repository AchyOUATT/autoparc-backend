<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrivateListingMarginRequest;
use App\Support\MargeAnnonceParticuliere;
use Illuminate\Http\JsonResponse;

class PrivateListingMarginController extends Controller
{
    public function showPublic(): JsonResponse
    {
        return response()->json(['taux' => MargeAnnonceParticuliere::taux()])
            ->header('Cache-Control', 'no-store');
    }

    public function show(): JsonResponse
    {
        return response()->json(MargeAnnonceParticuliere::etatAdmin())
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdatePrivateListingMarginRequest $request): JsonResponse
    {
        MargeAnnonceParticuliere::enregistrer((float) $request->validated('taux'), $request->user());

        return $this->show();
    }
}
