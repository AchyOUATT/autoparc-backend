<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(protected ReferenceGenerator $references)
    {
    }

    public function index(Request $request)
    {
        $customers = Customer::query()
            ->withCount(['sales', 'rentals'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where(fn ($sub) => $sub
                    ->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%"));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create(array_merge($request->validated(), [
            'code' => $this->references->next('CLI'),
        ]));

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->loadCount(['sales', 'rentals'])->load('country'));
    }

    public function update(StoreCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer->refresh());
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(['message' => 'Client archive.']);
    }
}
