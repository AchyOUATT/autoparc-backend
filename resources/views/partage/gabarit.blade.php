{{--
    Gabarit des pages de partage.

    Il n'existe que pour l'en-tete : les balises Open Graph sont la raison
    d'etre de ces pages, et elles sont ecrites UNE fois, ici, pour les trois
    types d'articles. C'est ce que le robot de WhatsApp ou de Facebook lit pour
    fabriquer la vignette de la conversation — et il ne lit que le HTML rendu
    par le serveur, jamais un script.

    Aucune balise <script>, aucune ressource externe, pas de police
    telechargee : la page s'ouvre sur un reseau mobile faible, et la feuille de
    style tient dans l'en-tete pour n'exiger qu'un seul aller-retour.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>{{ $article->titreAvecPrix() }}</title>
<meta name="description" content="{{ $article->resume() }}">

<meta property="og:type" content="product">
<meta property="og:site_name" content="{{ config('boutique.nom') }}">
{{-- Le nom ET le prix : c'est la ligne en gras que le destinataire lit dans
     sa conversation, et la seule qui lui dise si l'article l'interesse. --}}
<meta property="og:title" content="{{ $article->titreAvecPrix() }}">
<meta property="og:description" content="{{ $article->resume() }}">

{{-- DEUX ADRESSES, ET ELLES NE SONT PAS LA MEME. Les deux viennent de la
     CONFIGURATION et jamais de la requete : url()->current() suivait
     X-Forwarded-Host, auquel trustProxies(at: '*') fait confiance, et un
     en-tete fourni par le client pouvait donc placer l'adresse de son choix
     dans l'apercu. Voir ArticleController::adresse().

     og:url porte le MARQUEUR DE VERSION, c'est-a-dire l'adresse avec « ?m= ».
     Facebook identifie un objet par cette balise : un vieux lien visite apres
     une modification annonce le marqueur COURANT — jamais celui que l'appelant
     a envoye —, ce qui fait re-cler l'objet au lieu de figer la vignette du
     premier partage. Sans cela, un prix corrige en boutique reste faux dans
     tous les apercus deja construits, et aucun en-tete HTTP n'y change rien.
     Voir VersionPartage pour le format et pour la mesure. --}}
<meta property="og:url" content="{{ $urlPartage }}">
{{-- Le canonique, lui, reste NU — sans marqueur. Un moteur de recherche doit
     voir UNE page par article, pas une par modification : l'adresse marquee
     designe le meme contenu, et dix retouches feraient dix adresses indexables
     identiques. C'est la meme raison qui ecarte les parametres de suivi :
     /v/1?utm_source=x et /v/1 etaient deux pages pour l'indexation, alors
     qu'une page de partage n'en est qu'une. --}}
<link rel="canonical" href="{{ $url }}">

@if ($article->photo)
{{-- URL absolue, exigee par og:image — le robot ne resout pas de chemin
     relatif. Les photos vivent sur Cloudflare R2 et le sont deja. C'est la
     variante bornee a 1200x630 quand elle existe : l'original est une prise de
     telephone qui pese des megaoctets, et WhatsApp refuse la vignette au-dela
     de quelques centaines de ko. Le compte exact est dans VignetteApercu — il a
     change avec docker/php/depot-de-photos.ini. --}}
<meta property="og:image" content="{{ $article->photo->url }}">
@if ($article->photo->largeur && $article->photo->hauteur)
{{-- Dimensions annoncees : sans elles le robot doit telecharger l'image
     ENTIERE avant de composer la vignette, au premier appel — celui qui a deja
     le reveil de l'instance Render devant lui. Declarees seulement quand elles
     sont connues, c'est-a-dire pour une variante : une dimension fausse ferait
     recadrer sur un rapport qui n'est pas celui de l'image. --}}
<meta property="og:image:width" content="{{ $article->photo->largeur }}">
<meta property="og:image:height" content="{{ $article->photo->hauteur }}">
@endif
@if ($article->photo->type)
<meta property="og:image:type" content="{{ $article->photo->type }}">
@endif
{{-- Conditionnee a la photo, comme og:image. « summary_large_image » promet une
     grande image : rendue sans og:image, la carte annonce une vignette qui
     n'existe pas, et le lecteur recoit un cadre vide au lieu du resume compact
     qu'il aurait affiche de lui-meme. --}}
<meta name="twitter:card" content="summary_large_image">
@endif
{{-- Sans photo, ces balises sont ABSENTES et non vides : une balise vide ou
     pointant un fichier manquant donne une vignette cassee, ce qui fait
     passer la page pour une page en panne. --}}

@if ($article->montantBrut())
{{-- og:type vaut « product » : le couple product:price:amount +
     product:price:currency est ce qu'un lecteur de fiche produit attend, et le
     prix est deja calcule juste au-dessus. Valeur BRUTE, sans espace
     insecable ni separateur de milliers — elle est lue par une machine, pour
     qui « 4 500 000 » n'est pas un nombre. --}}
<meta property="product:price:amount" content="{{ $article->montantBrut() }}">
<meta property="product:price:currency" content="XOF">
@endif

@if ($article->estIndisponible())
{{-- La moitie LUE PAR UNE MACHINE de ce que titreAvecPrix() dit a l'humain.
     og:type vaut « product » et la page porte product:price:amount : sans
     cette balise, un agregateur lit un prix et en conclut un article a vendre.
     Les deux moities doivent dire la meme chose, sinon la page se contredit
     elle-meme — et c'est la version machine qui serait la fausse. --}}
<meta property="product:availability" content="out of stock">
@endif

<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        line-height: 1.5;
        color: #15202b;
        background: #f3f4f6;
    }
    .feuille {
        max-width: 560px;
        margin: 0 auto;
        background: #fff;
        min-height: 100vh;
    }
    .photo {
        display: block;
        width: 100%;
        height: auto;
        background: #e5e7eb;
    }
    .corps { padding: 16px; }
    h1 { font-size: 1.3rem; margin: 0 0 4px; }
    .prix {
        font-size: 1.5rem;
        font-weight: 700;
        color: #0f766e;
        margin: 0 0 12px;
    }
    /* Lisible sans couleur : le libelle porte le texte, pas seulement la
       teinte. Un lecteur daltonien ou un ecran au soleil doit le voir. */
    .indisponible {
        display: inline-block;
        margin: -4px 0 12px;
        padding: 4px 10px;
        border-radius: 6px;
        background: #fef2f2;
        border: 1px solid #fca5a5;
        color: #991b1b;
        font-weight: 700;
        font-size: .95rem;
    }
    .description { margin: 0 0 16px; color: #374151; }
    dl.precisions {
        margin: 0 0 20px;
        border-top: 1px solid #e5e7eb;
    }
    dl.precisions > div {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 8px 0;
        border-bottom: 1px solid #e5e7eb;
    }
    dl.precisions dt { color: #6b7280; }
    dl.precisions dd { margin: 0; font-weight: 600; text-align: right; }
    a.action {
        display: block;
        padding: 13px 16px;
        margin-bottom: 10px;
        border-radius: 8px;
        text-align: center;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid transparent;
    }
    a.whatsapp { background: #25d366; color: #fff; }
    a.appel    { background: #0f766e; color: #fff; }
    a.magasin  { background: #fff; color: #15202b; border-color: #d1d5db; }
    footer {
        padding: 16px;
        text-align: center;
        color: #6b7280;
        font-size: .85rem;
    }
</style>
</head>
<body>
<div class="feuille">
    @yield('contenu')

    <footer>{{ config('boutique.nom') }}</footer>
</div>
</body>
</html>
