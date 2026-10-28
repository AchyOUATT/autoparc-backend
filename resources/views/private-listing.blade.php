<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $listing->title }} — AutoParc</title>
    <meta name="description" content="{{ $listing->year }} · {{ number_format($listing->mileage_km, 0, ',', ' ') }} km · {{ $listing->city }} · Particulier">

    <meta property="og:type" content="product">
    <meta property="og:site_name" content="{{ config('boutique.nom') }}">
    <meta property="og:title" content="{{ $listing->title }} — {{ number_format($listing->price, 0, ',', ' ') }} FCFA">
    <meta property="og:description" content="{{ $listing->year }} · {{ number_format($listing->mileage_km, 0, ',', ' ') }} km · {{ $listing->city }} · Particulier">
    {{-- L'adresse vient de la configuration, pas de la requete : voir
         PrivateVehicleListingController::share(). Le canonique est le meme ici
         — une annonce de particulier n'a pas de marqueur de version, son
         contenu ne bouge plus une fois publie. --}}
    <meta property="og:url" content="{{ $url }}">
    <link rel="canonical" href="{{ $url }}">

    @if($apercu)
        {{-- La variante bornee a 1200x630, et l'original seulement a defaut.
             Servir l'original tel quel laissait le lien partir SANS vignette :
             WhatsApp refuse au-dela de quelques centaines de ko, la ou une
             photo de 2048 px pese pres de 700 ko. Le compte est dans
             VignetteApercu. --}}
        <meta property="og:image" content="{{ $apercu->url }}">
        @if($apercu->largeur && $apercu->hauteur)
            {{-- Les dimensions epargnent au robot le telechargement de l'image
                 ENTIERE avant de composer la vignette — au premier appel, celui
                 qui a deja le reveil de l'instance Render devant lui. On ne les
                 annonce que REELLES : une dimension fausse vaut moins que pas
                 de dimension du tout. --}}
            <meta property="og:image:width" content="{{ $apercu->largeur }}">
            <meta property="og:image:height" content="{{ $apercu->hauteur }}">
        @endif
        @if($apercu->type)
            <meta property="og:image:type" content="{{ $apercu->type }}">
        @endif
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <style>body{font:17px system-ui;background:#f5f6f8;color:#18273a;margin:0}main{max-width:760px;margin:auto;padding:24px}.badge,a{background:#164c41;color:white;padding:10px 16px;border-radius:12px;text-decoration:none;display:inline-block}img{width:100%;border-radius:16px;margin:8px 0}p{line-height:1.6}.description{white-space:pre-wrap}small{display:block;margin:20px 0}</style>
</head>
<body><main>
    <span class="badge">Particulier</span>
    <h1>{{ $listing->title }}</h1>
    <h2>{{ number_format($listing->price, 0, ',', ' ') }} FCFA</h2>
    <p>{{ $listing->year }} · {{ number_format($listing->mileage_km, 0, ',', ' ') }} km · {{ $listing->city }}</p>
    <p class="description">{{ $listing->description }}</p>
    @foreach($listing->photos as $photo)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk($photo->disk)->url($photo->path) }}" alt="Photo du véhicule" loading="lazy">
    @endforeach
    {{-- « Téléphone vérifié » est une affirmation, pas une décoration : elle
         dit au visiteur qu'une personne d'AutoParc a appelé ce numéro et que
         le vendeur a confirmé avoir déposé l'annonce. `approve()` l'exige pour
         publier, donc elle est vraie de toute annonce servie ici — mais on la
         pose sur la DONNÉE et non sur cette garantie, pour qu'elle disparaisse
         d'elle-même si le chemin de publication change un jour. Une mention de
         confiance qui survit à ce qui la fondait est pire que son absence. --}}
    <p>{{ $listing->seller_name }}@if($listing->phone_verified_at) · Téléphone vérifié@endif</p>
    <a href="tel:{{ $listing->verified_phone }}">Appeler le vendeur</a>
    @if($listing->whatsapp_enabled)
        <a href="https://wa.me/{{ ltrim($listing->verified_phone, '+') }}">Contacter sur WhatsApp</a>
    @endif
    <small>Annonce d’un particulier. Le téléphone a été vérifié ; le véhicule n’a pas été inspecté par AutoParc.</small>
</main></body></html>
