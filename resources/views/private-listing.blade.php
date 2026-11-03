@php($mileageLabel = $listing->mileage_km === null ? 'Kilométrage non renseigné' : number_format($listing->mileage_km, 0, ',', ' ').' km')
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $listing->title }} — AutoParc</title>
    <meta name="description" content="{{ $listing->year }} · {{ $mileageLabel }} · {{ $listing->city }} · Particulier">

    <meta property="og:type" content="product">
    <meta property="og:site_name" content="{{ config('boutique.nom') }}">
    <meta property="og:title" content="{{ $listing->title }} — {{ number_format($listing->price, 0, ',', ' ') }} FCFA">
    <meta property="og:description" content="{{ $listing->year }} · {{ $mileageLabel }} · {{ $listing->city }} · Particulier">
    {{-- L'adresse vient de la configuration, pas de la requete : voir
         PrivateVehicleListingController::share(). Le canonique est le meme ici
         — une annonce de particulier n'a pas de marqueur de version, son
         contenu ne bouge plus une fois publie. --}}
    <meta property="og:url" content="{{ $url }}">
    <link rel="canonical" href="{{ $url }}">

    {{-- A withdrawn listing must not remain indexed. Keep preview robots able
         to read Open Graph; no robots.txt exclusion is needed. The view only
         receives the public listing snapshot, without the seller's contact. --}}
    <meta name="robots" content="noindex, nofollow">

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
    <p>{{ $listing->year }} · {{ $mileageLabel }} · {{ $listing->city }}</p>
    <p class="description">{{ $listing->description }}</p>
    <section aria-label="Défauts déclarés par le vendeur">
        <h2>Défauts déclarés par le vendeur</h2>
        @if($listing->fault_declaration['state'] === 'unknown')
            <p>Déclaration des défauts non renseignée.</p>
        @elseif($listing->fault_declaration['state'] === 'none')
            <p>Le vendeur déclare ne connaître aucun défaut. Cette déclaration ne constitue pas une inspection AutoParc.</p>
        @else
            <p>{{ $listing->fault_declaration['unresolved_count'] }} défaut(s) déclaré(s) non réparé(s).</p>
            <ul>
                @foreach($listing->fault_declaration['faults'] as $fault)
                    <li><strong>{{ $fault['title'] }}</strong>
                        — {{ ['repaired' => 'réparé', 'wont_fix' => 'non réparé, réparation non prévue'][$fault['status']] ?? 'déclaré' }}
                        @if($fault['description'])<p>{{ $fault['description'] }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @foreach($listing->photos as $photo)
        <img src="{{ $photo['url'] }}" alt="Photo du véhicule" loading="lazy">
    @endforeach
    <p>Contactez AutoParc pour cette annonce.</p>
    <a href="tel:{{ $autoparc_contact['phone'] }}">Appeler AutoParc</a>
    <a href="https://wa.me/{{ $autoparc_contact['whatsapp'] }}?text={{ rawurlencode('Bonjour AutoParc, je vous contacte pour cette annonce : '.$url) }}">Contacter AutoParc sur WhatsApp</a>
    <small>Annonce d’un particulier. Les échanges passent par AutoParc.</small>
    @if($listing->inspection)
        <section aria-label="Inspection AutoParc">
            <span class="badge">✓ Inspecté par AutoParc</span>
            <p>Contrôlé le {{ \Carbon\Carbon::parse($listing->inspection['inspected_at'])->format('d/m/Y') }}
                à {{ number_format($listing->inspection['mileage_km'], 0, ',', ' ') }} km.
                {{ $listing->inspection['checked_count'] }} point(s) contrôlé(s),
                {{ $listing->inspection['attention_count'] }} point(s) à surveiller,
                {{ $listing->inspection['blocking_count'] }} anomalie(s) bloquante(s).</p>
            <a href="{{ url('/api/catalog/vehicle-inspections/'.$listing->inspection['id']) }}">Consulter le compte rendu</a>
            <small>Constat au moment du contrôle sur les points indiqués. Cette inspection ne constitue pas une garantie.</small>
        </section>
    @else
        <small>Le véhicule n’a pas été inspecté par AutoParc.</small>
    @endif
</main></body></html>
