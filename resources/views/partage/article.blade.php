{{--
    La page d'un article : vehicule, piece ou accessoire.

    Elle ne connait aucun des trois modeles — seulement l'objet de presentation
    prepare par le controleur. Un seul gabarit et une seule vue, donc une seule
    place ou les balises de l'apercu peuvent se desynchroniser : aucune.

    Rien d'interne ici. Ni prix d'achat, ni marge, ni emplacement de stockage,
    ni fournisseur : la page s'ouvre sans compte, et un lien partage finit par
    circuler au-dela de ceux a qui on l'a envoye.
--}}
@extends('partage.gabarit')

@section('contenu')
    @if ($article->photo)
        {{-- La photo porte l'apercu, donc la page : en grand, et en premier.
             `loading` reste par defaut (eager) — c'est l'image que le lecteur
             vient voir, la differer sur un reseau faible ne gagne rien. --}}
        <img class="photo" src="{{ $article->photo->url }}" alt="{{ $article->titre }}"
             @if ($article->photo->largeur && $article->photo->hauteur)
                 width="{{ $article->photo->largeur }}" height="{{ $article->photo->hauteur }}"
             @endif>
    @endif

    <div class="corps">
        <h1>{{ $article->titre }}</h1>
        {{-- ligneDePrix() et non prixFormate() : en l'absence de prix de vente,
             c'est le tarif de location qui s'affiche. Mesure avant correction,
             availability=rent avec rental_daily_rate=25000 : la page annoncait
             « Prix sur demande » et les 25 000 FCFA n'apparaissaient NULLE
             PART — alors que c'est la seule information qui decide le
             destinataire. --}}
        <p class="prix">{{ $article->ligneDePrix() }}</p>

        @if ($article->estIndisponible())
            {{-- La regle d'ArticleController::catalogueMontre sert une piece en
                 rupture, parce que le catalogue des pieces la montre. Servie, la
                 page doit le DIRE : sans cette ligne, le lecteur voit un prix,
                 un bouton WhatsApp, et ecrit pour un article qui n'est pas la.
                 Le prix reste juste au-dessus a dessein — c'est lui qui decide
                 d'attendre le reapprovisionnement ou de chercher ailleurs. --}}
            <p class="indisponible">{{ $article->indisponibilite }}</p>
        @endif

        @if ($article->description)
            <p class="description">{{ $article->description }}</p>
        @endif

        @if ($article->precisions !== [])
            <dl class="precisions">
                @foreach ($article->precisions as $libelle => $valeur)
                    <div>
                        <dt>{{ $libelle }}</dt>
                        <dd>{{ $valeur }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        {{-- WhatsApp d'abord : c'est par la que les clients ecrivent, et le
             message est pre-rempli du nom de l'article — sans cela le vendeur
             recoit « bonjour » et doit demander de quoi on parle. --}}
        <a class="action whatsapp" href="{{ $whatsapp }}">Contacter sur WhatsApp</a>
        <a class="action appel" href="tel:{{ $telephone }}">Appeler {{ $telephone }}</a>
        {{-- Le lien du magasin reste en dernier : le visiteur arrive ici parce
             qu'il n'a PAS l'application, et lui demander de l'installer avant
             de pouvoir ecrire est exactement ce que ces pages corrigent. --}}
        <a class="action magasin" href="{{ $storeUrl }}">Ouvrir dans AutoParc</a>
    </div>
@endsection
