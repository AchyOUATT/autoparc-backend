<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\VehicleModelResource;
use App\Models\Brand;
use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $brands = Brand::query()
            ->with('country')
            // Le compte ne retient que les modeles PROPOSABLES. Sans ce filtre,
            // une marque annoncait « 35 modeles » dont deux que le selecteur
            // allait montrer en double (voir `models()` ci-dessous) : le chiffre
            // promettait un choix que la liste ne tenait pas.
            ->withCount(['vehicleModels' => fn ($q) => $q->where('is_active', true)])
            ->when($request->filled('q'), fn ($q) => $q->whereLike('name', '%'.$request->q.'%'))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:80', Rule::unique('brands', 'name')],
            'country_id' => ['nullable', 'exists:countries,id'],
            'logo_path'  => ['nullable', 'string', 'max:255'],
            'oem_prefix' => ['nullable', 'string', 'max:20'],
        ]);

        return (new BrandResource(Brand::create($data)))->response()->setStatusCode(201);
    }

    public function show(Brand $brand): BrandResource
    {
        // Meme regle que `models()` : la fiche d'une marque est une liste de
        // choix, pas un inventaire. Un modele desactive n'y figure pas.
        return new BrandResource($brand->load([
            'country',
            'vehicleModels' => fn ($q) => $q->where('is_active', true),
            'vehicleModels.trims',
        ]));
    }

    public function update(Request $request, Brand $brand): BrandResource
    {
        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:80', Rule::unique('brands', 'name')->ignore($brand->id)],
            'country_id' => ['nullable', 'exists:countries,id'],
            'logo_path'  => ['nullable', 'string', 'max:255'],
            'oem_prefix' => ['nullable', 'string', 'max:20'],
            'is_active'  => ['boolean'],
        ]);

        $brand->update($data);

        return new BrandResource($brand->refresh());
    }

    /**
     * Modeles d'une marque, pour le selecteur.
     *
     * ── POURQUOI CE FILTRE EXISTE ────────────────────────────────────────
     *
     * Cette route ne filtrait pas `is_active`, si bien que DESACTIVER UN
     * MODELE NE LE CACHAIT PAS. Tant que rien n'etait desactive, personne ne
     * pouvait s'en apercevoir — la colonne existait, le drapeau se posait, et
     * la liste n'en tenait aucun compte.
     *
     * Le 23/10/2026, les migrations du lot Toyota ont remplace les lignes nues
     * `tacoma` et `venza` par leurs generations et desactive les deux lignes
     * nues. Le selecteur s'est alors mis a proposer « Tacoma » A COTE de
     * Tacoma I, II, III et IV, et « Venza » a cote de ses deux generations :
     * six entrees pour deux vehicules, dont deux qui ne menent a aucune
     * finition et a aucune cote. C'est ce jour-la que le defaut est devenu
     * visible, pas ce jour-la qu'il est apparu.
     *
     * ── CE QUE CE FILTRE NE FAIT PAS, ET POURQUOI ────────────────────────
     *
     * La synchronisation incrementale, elle, continue de servir les lignes
     * desactivees AVEC leur drapeau (`CatalogSyncController`, colonne
     * `is_active` dans la charge utile). C'est volontaire et c'est l'inverse
     * de ce qu'on ferait ici par symetrie : si le delta cessait de les
     * renvoyer, une application deja installee garderait indefiniment en cache
     * un modele qu'elle ne saurait jamais devoir oublier. Une liste de choix
     * se filtre au serveur ; un cache s'informe.
     *
     * `active_only=0` rend l'inventaire complet, pour l'administration du
     * referentiel. Le defaut est `true`, comme sur `index()`.
     */
    public function models(Request $request, Brand $brand)
    {
        return VehicleModelResource::collection(
            $brand->vehicleModels()
                ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
                ->with('trims')
                ->orderBy('name')
                ->get()
        );
    }

    public function storeModel(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:80'],
            'generation'       => ['nullable', 'string', 'max:40'],
            'body_type'        => ['nullable', 'string', 'max:40'],
            'segment'          => ['nullable', 'string', 'max:10'],
            'production_start' => ['nullable', 'integer', 'min:1950'],
            'production_end'   => ['nullable', 'integer', 'min:1950', 'gte:production_start'],
        ]);

        $model = $brand->vehicleModels()->create($data);

        return (new VehicleModelResource($model))->response()->setStatusCode(201);
    }

    /** Ajout d'un niveau de finition a un modele. */
    public function storeTrim(Request $request, VehicleModel $vehicleModel): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:60'],
            'code'        => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'rank'        => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        $trim = $vehicleModel->trims()->create($data);

        return response()->json(['data' => $trim], 201);
    }
}
