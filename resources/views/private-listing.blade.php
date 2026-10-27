<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $listing->title }} — AutoParc</title>
    <meta property="og:title" content="{{ $listing->title }} — {{ number_format($listing->price, 0, ',', ' ') }} FCFA">
    <meta property="og:description" content="{{ $listing->year }} · {{ number_format($listing->mileage_km, 0, ',', ' ') }} km · {{ $listing->city }} · Particulier">
    @if($photo = $listing->photos->first())
        <meta property="og:image" content="{{ \Illuminate\Support\Facades\Storage::disk($photo->disk)->url($photo->path) }}">
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
    <p>{{ $listing->seller_name }} · Téléphone vérifié</p>
    <a href="tel:{{ $listing->verified_phone }}">Appeler le vendeur</a>
    @if($listing->whatsapp_enabled)
        <a href="https://wa.me/{{ ltrim($listing->verified_phone, '+') }}">Contacter sur WhatsApp</a>
    @endif
    <small>Annonce d’un particulier. Le téléphone a été vérifié ; le véhicule n’a pas été inspecté par AutoParc.</small>
</main></body></html>
