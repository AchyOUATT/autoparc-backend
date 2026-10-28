<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContactRequest;
use App\Services\AutoParcContact;
use Illuminate\Http\JsonResponse;

class ContactSettingsController extends Controller
{
    public function show(AutoParcContact $contact): JsonResponse
    {
        return response()->json(['phone' => $contact->phone()])
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateContactRequest $request, AutoParcContact $contact): JsonResponse
    {
        $contact->updatePhone($request->validated('phone'));

        return $this->show($contact);
    }
}
