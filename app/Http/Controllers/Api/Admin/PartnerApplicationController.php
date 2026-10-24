<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnerAccountResource;
use App\Models\PartnerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerApplicationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(PartnerAccount::STATUSES)],
            'q' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PartnerAccountResource::collection(
            PartnerAccount::query()->with('user')
                ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($data['q'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search) {
                    $query->whereLike('company_name', '%'.$search.'%')
                        ->orWhereLike('contact_name', '%'.$search.'%')
                        ->orWhereHas('user', fn ($user) => $user->whereLike('email', '%'.$search.'%'));
                }))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 25)->withQueryString(),
        );
    }

    public function show(PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return new PartnerAccountResource($partnerAccount->load('user'));
    }

    public function approve(Request $request, PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return $this->review($request, $partnerAccount, 'approved');
    }

    public function reject(Request $request, PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return $this->review($request, $partnerAccount, 'rejected');
    }

    public function suspend(Request $request, PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return $this->review($request, $partnerAccount, 'suspended');
    }

    private function review(Request $request, PartnerAccount $partnerAccount, string $status): PartnerAccountResource
    {
        $data = $request->validate([
            'review_reason' => [$status === 'approved' ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);

        $account = DB::transaction(function () use ($request, $partnerAccount, $status, $data) {
            // Meme ordre de verrous que la connexion : utilisateur, puis compte.
            $user = $partnerAccount->user()->lockForUpdate()->firstOrFail();
            $account = PartnerAccount::query()->lockForUpdate()->findOrFail($partnerAccount->id);
            $account->update([
                'status' => $status,
                'review_reason' => $data['review_reason'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            if (in_array($status, ['rejected', 'suspended'], true)) {
                $user->tokens()->delete();
            }

            return $account->setRelation('user', $user);
        });

        return new PartnerAccountResource($account);
    }
}
