<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrivateVehicleListingResource;
use App\Enums\Transmission;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\OwnedVehicle;
use App\Models\PrivateVehicleListing;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use App\Support\PartnerMedia;
use App\Support\PhotoPartage;
use App\Support\VehicleClassification;
use App\Support\VignetteApercu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrivateVehicleListingController extends Controller
{
    public function index(Request $request)
    {
        $query = PrivateVehicleListing::published()->with('photos')->latest('published_at');
        $filters = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(fn ($q) => $q->whereLike('title', '%'.$search.'%')->orWhereLike('city', '%'.$search.'%'));
        }

        return PrivateVehicleListingResource::collection($query->paginate(20));
    }

    public function show(PrivateVehicleListing $listing)
    {
        abort_unless(PrivateVehicleListing::published()->whereKey($listing->id)->exists(), 404);

        return new PrivateVehicleListingResource($listing->load('photos'));
    }

    public function mine(Request $request)
    {
        return PrivateVehicleListingResource::collection(
            PrivateVehicleListing::where('user_id', $request->user()->id)->with('photos')->latest('id')->paginate(20)
        );
    }

    public function ownedShow(Request $request, PrivateVehicleListing $listing)
    {
        $this->authorizeOwner($request, $listing);

        return new PrivateVehicleListingResource($listing->load('photos'));
    }

    public function store(Request $request)
    {
        $data = $this->validateFields($request);
        if (isset($data['owned_vehicle_id'])) {
            abort_unless(OwnedVehicle::whereKey($data['owned_vehicle_id'])->where('user_id', $request->user()->id)->exists(), 404);
        }

        $listing = DB::transaction(function () use ($request, $data) {
            // All creation requests for this seller serialize, even on an empty listings table.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (PrivateVehicleListing::where('active_user_id', $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['listing' => 'Vous avez déjà une annonce en cours. Retirez-la ou marquez-la vendue avant d’en créer une autre.']);
            }

            return PrivateVehicleListing::create($data + [
                'user_id' => $request->user()->id, 'active_user_id' => $request->user()->id,
                'status' => 'draft', 'currency' => 'XOF',
            ]);
        });

        return (new PrivateVehicleListingResource($listing->load('photos')))->response()->setStatusCode(201);
    }

    public function update(Request $request, PrivateVehicleListing $listing)
    {
        $this->authorizeOwner($request, $listing);
        $data = $this->validateFields($request, false, $listing);
        DB::transaction(function () use ($listing, $data) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            $this->requireEditable($locked);
            $locked->fill($data);
            $locked->resetReview();
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function submit(Request $request, PrivateVehicleListing $listing)
    {
        $this->authorizeOwner($request, $listing);
        $request->validate(['owner_confirmed' => ['required', 'accepted'], 'contact_consent' => ['required', 'accepted']]);
        // This number comes only from the verified Firebase ID token, never the form or User.phone.
        $phone = $request->attributes->get('firebase_verified_phone');
        if (! is_string($phone) || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Vérifiez votre téléphone par SMS avant d’envoyer l’annonce.']);
        }

        DB::transaction(function () use ($listing, $phone) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['draft', 'rejected'], true), 422, 'Cette annonce ne peut pas être envoyée dans son état actuel.');
            abort_unless($locked->photos()->exists(), 422, 'Ajoutez au moins une photo.');
            $locked->update([
                'status' => 'pending', 'verified_phone' => $phone, 'phone_verified_at' => now(),
                'submitted_at' => now(), 'review_reason' => null, 'reviewed_by' => null,
                'revision' => $locked->revision + 1,
            ]);
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function withdraw(Request $request, PrivateVehicleListing $listing)
    {
        return $this->close($request, $listing, 'withdrawn');
    }

    public function sold(Request $request, PrivateVehicleListing $listing)
    {
        return $this->close($request, $listing, 'sold');
    }

    private function close(Request $request, PrivateVehicleListing $listing, string $status)
    {
        $this->authorizeOwner($request, $listing);
        DB::transaction(function () use ($listing, $status) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            $this->requireEditable($locked);
            if ($status === 'sold') {
                abort_unless($locked->status === 'published', 422, 'Seule une annonce publiée peut être marquée vendue.');
            }
            $locked->update(['status' => $status, 'active_user_id' => null, 'published_at' => null, 'revision' => $locked->revision + 1]);
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function upload(Request $request, PrivateVehicleListing $listing)
    {
        $this->authorizeOwner($request, $listing);
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:9216', 'dimensions:max_width=2048,max_height=2048'],
        ]);
        $disk = config('media.disk', 'public');
        abort_unless(in_array($disk, PartnerMedia::publicDisks(), true) && function_exists('imagecreatefromstring'), 503, 'Le stockage des photos est indisponible.');
        $paths = [];
        try {
            DB::transaction(function () use ($listing, $request, $disk, &$paths) {
                $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
                $this->requireEditable($locked);
                abort_if($locked->photos()->count() + count($request->file('photos')) > 10, 422, 'Une annonce peut contenir au plus 10 photos.');
                foreach ($request->file('photos') as $photo) {
                    $image = imagecreatefromstring(file_get_contents($photo->getRealPath()));
                    abort_unless($image !== false, 422, 'Photo illisible.');
                    // Le reencodage retire les metadonnees GPS/EXIF avant que
                    // la photo devienne publique.
                    //
                    // APLATISSEMENT SUR DU BLANC, et pourquoi il faut y penser
                    // ici alors que la photo vient d'un appareil.
                    //
                    // La validation accepte `png` et `webp`, et ce n'est pas
                    // theorique : image_picker_android redimensionne, et son
                    // ImageResizer decide du format de sortie avec
                    // `boolean saveAsPNG = bitmap.hasAlpha();`. Une capture,
                    // un visuel recu par messagerie, un montage arrivent donc
                    // en PNG a canal alpha. GD ecrit alors le RGB stocke SOUS
                    // l'alpha, et ce que Skia laisse sous un pixel
                    // completement transparent est du noir : la photo
                    // ressortait en pave noir dans l'annonce, sur la page de
                    // partage et dans l'apercu WhatsApp.
                    //
                    // C'est le meme defaut que VignetteApercu a deja paye et
                    // corrige — « un logo de piece sur fond transparent
                    // sortait en pave noir dans la conversation » —, et ce
                    // sont ses trois lignes qu'on reprend ici.
                    $opaque = imagecreatetruecolor(imagesx($image), imagesy($image));
                    abort_unless($opaque !== false, 503, 'Le stockage des photos est indisponible.');
                    imagefill($opaque, 0, 0, imagecolorallocate($opaque, 255, 255, 255));
                    imagecopyresampled(
                        $opaque, $image,
                        0, 0, 0, 0,
                        imagesx($image), imagesy($image),
                        imagesx($image), imagesy($image),
                    );
                    imagedestroy($image);
                    ob_start();
                    imagejpeg($opaque, null, 88);
                    $bytes = ob_get_clean();
                    imagedestroy($opaque);
                    $path = 'private-listings/'.$locked->id.'/'.Str::uuid().'.jpg';
                    $paths[] = $path;
                    abort_unless(Storage::disk($disk)->put($path, $bytes, 'public'), 503, 'La photo n’a pas pu être enregistrée. Réessayez.');

                    // LA VARIANTE DE L'APERCU, fabriquee au depot.
                    //
                    // C'est elle qu'og:image sert, et sans elle le lien partage
                    // arrive sans photo : WhatsApp refuse la vignette au-dela
                    // de quelques centaines de ko, la ou l'original pese pres
                    // de 700 ko. Le compte et la mesure sont dans
                    // VignetteApercu et docker/php/depot-de-photos.ini.
                    //
                    // `generer` ne leve jamais et rend `null` a chaque
                    // empechement — GD absent, image trop grande pour la
                    // memoire, encodeur sans JPEG : le depot doit aboutir meme
                    // quand la variante echoue, et la page retombe alors sur
                    // l'original, qui donne un apercu mediocre mais existant.
                    $apercu = VignetteApercu::generer($disk, $path);
                    if ($apercu !== null) {
                        $paths[] = $apercu['chemin'];
                    }

                    $locked->photos()->create([
                        'disk' => $disk, 'path' => $path,
                        'preview_path' => $apercu['chemin'] ?? null,
                        'preview_width' => $apercu['largeur'] ?? null,
                        'preview_height' => $apercu['hauteur'] ?? null,
                    ]);
                }
                $locked->resetReview();
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $error;
        }

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function deletePhoto(Request $request, PrivateVehicleListing $listing, int $photo)
    {
        $this->authorizeOwner($request, $listing);
        DB::transaction(function () use ($listing, $photo) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            $this->requireEditable($locked);
            $image = $locked->photos()->whereKey($photo)->firstOrFail();
            $image->delete();
            $locked->resetReview();
            // La variante part avec l'original : la laisser derriere ferait
            // grossir le stockage de fichiers que plus rien ne designe.
            $fichiers = array_values(array_filter([$image->path, $image->preview_path]));
            DB::afterCommit(fn () => Storage::disk($image->disk)->delete($fichiers));
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function share(PrivateVehicleListing $listing)
    {
        abort_unless(PrivateVehicleListing::published()->whereKey($listing->id)->exists(), 404);

        $listing->load('photos');
        $couverture = $listing->photos->first();

        return response()->view('private-listing', [
            'listing' => $listing,
            // Ce que l'apercu sert, et ses dimensions : la variante bornee a
            // 1200x630 quand elle existe, l'original sinon, et `null` quand le
            // disque n'offre aucune adresse qu'un robot puisse atteindre. Toute
            // cette decision vit dans PhotoPartage, qui l'a payee une fois pour
            // le catalogue — voir `depuisUnFichier`.
            'apercu' => $couverture === null ? null : PhotoPartage::depuisUnFichier(
                disque: (string) $couverture->disk,
                chemin: $couverture->path,
                cheminApercu: $couverture->preview_path,
                largeurApercu: $couverture->preview_width,
                hauteurApercu: $couverture->preview_height,
            ),
            // L'adresse vient de la CONFIGURATION et jamais de la requete :
            // `url()->current()` suit `X-Forwarded-Host`, auquel
            // `trustProxies(at: '*')` fait confiance, et un en-tete fourni par
            // l'appelant placerait donc l'adresse de son choix dans l'apercu.
            // Meme regle que ArticleController::adresse().
            'url' => rtrim((string) config('app.url'), '/').'/annonces/'.$listing->id,
        ])->header('Cache-Control', 'no-store');
    }

    private function authorizeOwner(Request $request, PrivateVehicleListing $listing): void
    {
        abort_unless((int) $listing->user_id === (int) $request->user()->id, 404);
    }

    private function requireEditable(PrivateVehicleListing $listing): void
    {
        abort_unless($listing->editable(), 422, 'Cette annonce est clôturée.');
    }

    private function validateFields(Request $request, bool $creating = true, ?PrivateVehicleListing $listing = null): array
    {
        // Reuse the same catalog identity and consistency rules as the existing
        // garage/stock forms. Inactive references can only be retained by the
        // announcement that already selected them; they cannot be newly chosen.
        $activeReference = fn (string $table, string $field) => Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('is_active', true)
                ->when($listing?->getAttribute($field) !== null,
                    fn ($q) => $q->orWhere('id', $listing->getAttribute($field))));
        $rules = [
            'brand_id' => ['required', 'integer', $activeReference('brands', 'brand_id')],
            'vehicle_model_id' => ['nullable', 'required_without:model_libre', 'integer', $activeReference('vehicle_models', 'vehicle_model_id')],
            'model_libre' => ['nullable', 'required_without:vehicle_model_id', 'string', 'max:80'],
            'trim_id' => ['nullable', 'integer', 'exists:trims,id'],
            'engine_type_id' => ['nullable', 'integer', 'exists:engine_types,id'],
            'drivetrain_id' => ['nullable', 'integer', 'exists:drivetrains,id'],
            'color_id' => ['nullable', 'integer', 'exists:colors,id'],
            'year' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'mileage_km' => ['required', 'integer', 'min:0', 'max:3000000'],
            'price' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'city' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'seller_name' => ['required', 'string', 'max:80'],
            'transmission' => ['nullable', Rule::in(Transmission::values())],
            'whatsapp_enabled' => ['required', 'boolean'],
        ];
        if ($creating) {
            $rules['owned_vehicle_id'] = ['nullable', 'integer'];
        }
        $allowedFields = array_fill_keys(array_keys($rules), true);
        foreach (['title', 'vehicle_identity', 'fuel_type', 'status', 'revision', 'user_id', 'active_user_id', 'verified_phone', 'phone_verified_at', 'published_at', 'reviewed_by', 'review_reason', 'currency', 'seller_type'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        // PUT is a complete form: absent optional classifications are cleared,
        // preventing an old trim from silently following a newly selected model.
        $input = array_replace(array_fill_keys([
            'vehicle_model_id', 'model_libre', 'trim_id', 'engine_type_id',
            'drivetrain_id', 'color_id', 'transmission',
        ], null), $request->all());
        $validator = Validator::make($input, $rules);
        $validator->after(fn ($validator) => VehicleClassification::validate($validator, array_intersect_key($input, $allowedFields)));
        $validator->after(function ($validator) use ($input) {
            if ($input['vehicle_model_id'] !== null && ! empty($input['model_libre'])) {
                $validator->errors()->add('model_libre', 'Choisissez un modèle du catalogue ou une saisie libre, pas les deux.');
            }
        });
        // `prohibited` permits empty/null values. They must still NEVER enter
        // a mass assignment or shadow a server-owned default through array union.
        $data = array_intersect_key($validator->validate(), $allowedFields);
        $brand = Brand::findOrFail($data['brand_id']);
        $model = isset($data['vehicle_model_id']) ? VehicleModel::findOrFail($data['vehicle_model_id']) : null;
        $trim = isset($data['trim_id']) ? Trim::findOrFail($data['trim_id']) : null;
        $engine = isset($data['engine_type_id']) ? EngineType::findOrFail($data['engine_type_id']) : null;
        $identity = [
            'brand' => $brand->name, 'model' => $model?->name ?? trim($data['model_libre']),
            'generation' => $model?->generation, 'trim' => $trim?->name,
            'engine_type' => $engine?->label,
            'drivetrain' => isset($data['drivetrain_id']) ? Drivetrain::findOrFail($data['drivetrain_id'])->label : null,
            'color' => isset($data['color_id']) ? Color::findOrFail($data['color_id'])->name : null,
        ];
        $data['title'] = Str::limit(implode(' ', array_filter([
            $identity['brand'], $identity['model'], $identity['generation'], $identity['trim'],
        ], fn ($part) => $part !== null && $part !== '')), 120, '');
        $data['vehicle_identity'] = $identity;
        $data['fuel_type'] = $engine?->code;

        return $data;
    }
}
