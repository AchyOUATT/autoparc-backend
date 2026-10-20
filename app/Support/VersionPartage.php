<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Le marqueur de version d'un article, pose dans l'adresse de partage.
 *
 * POURQUOI IL EXISTE. Le defaut signale par le proprietaire — « un vehicule
 * deja partage ne met pas a jour ses donnees de partage apres maj » — n'est pas
 * un defaut du serveur, et c'est mesure : la page /v/86 annonce
 * « Mazda CX-9 2018 — 9 500 000 FCFA » et l'API rend sale_price 9500000.00. Les
 * deux concordent. Ce qui ne bouge pas, c'est la VIGNETTE DEJA CONSTRUITE chez
 * l'operateur de messagerie : WhatsApp et Facebook la gardent telle qu'ils
 * l'ont fabriquee au premier partage, et aucun en-tete HTTP ne les en fait
 * changer — pas Cache-Control, pas Expires, pas ETag. La seule prise qu'on ait
 * sur eux est l'ADRESSE APPELEE. Un article modifie doit donc en produire une
 * nouvelle, et c'est tout l'objet de ce marqueur :
 *
 *     avant   https://autoparc-backend.onrender.com/v/86
 *     apres   https://autoparc-backend.onrender.com/v/86?m=t3uwow
 *
 * LE FORMAT RETENU : la seconde UNIX de `updated_at`, ecrite en base 36.
 * Mesure, avec l'instant 1760000000 :
 *
 *     base_convert('1760000000', 10, 36)  ->  't3uwow'      (6 caracteres)
 *     base_convert('t3uwow', 36, 10)      ->  '1760000000'
 *
 * Cinq raisons de preferer ce format a un condensat (md5/sha1 tronque), qui
 * remplissait le contrat aussi bien sur le papier.
 *
 *   1. DERIVE DE `updated_at` SEUL, comme le contrat l'exige — aucune autre
 *      colonne, aucun sel, aucune configuration.
 *
 *   2. STABLE, et plus strictement que par une chaine de date. Le timestamp est
 *      un instant ABSOLU : il ne depend pas de config('app.timezone'). Un
 *      marqueur derive de toIso8601String() aurait change pour TOUS les
 *      articles a la fois le jour ou ce reglage bouge — c'est-a-dire une
 *      re-indexation complete chez Facebook sans qu'aucun article n'ait change.
 *      Pas d'horloge courante non plus : deux lectures a deux heures
 *      differentes du meme article rendent le meme marqueur, et deux instances
 *      de Render aussi.
 *
 *   3. COURT ET SANS ECHAPPEMENT. L'alphabet de la base 36 est [0-9a-z] : rien
 *      a percent-encoder dans une chaine de requete, pas de « + » ni de « / »
 *      a confondre. Mesure des bornes : 36^5 = 60 466 176 (1971-12-01) et
 *      36^6 = 2 176 782 336 (2038-12-24), donc SIX caracteres pour tout
 *      `updated_at` compris entre ces deux dates — tous ceux qui existeront
 *      avant 2039 — puis sept.
 *
 *   4. REVERSIBLE, et c'est l'argument decisif contre le condensat. Un lien
 *      continue de circuler des mois apres son envoi ; quand le proprietaire
 *      ecrit « mon lien montre encore l'ancien prix », base_convert(m, 36, 10)
 *      dit DE QUELLE VERSION ce lien a ete fabrique. Un condensat ne dit rien,
 *      et c'est exactement la question qu'on a a trancher en production.
 *
 *   5. MONOTONE. `updated_at` ne recule pas, donc le marqueur non plus : il n'y
 *      a pas de cas ou une modification ramene un marqueur deja vu, c'est-a-dire
 *      deja cle chez l'operateur. Un condensat n'offre pas cette garantie — il
 *      la rend seulement improbable.
 *
 * CE QU'IL NE DISTINGUE PAS, et aucun format ne le ferait : deux ecritures dans
 * la MEME SECONDE rendent le meme marqueur. Mesure du format de date
 * d'Eloquent sur les trois pilotes du projet :
 *
 *     sqlite dateFormat = Y-m-d H:i:s
 *     pgsql  dateFormat = Y-m-d H:i:s
 *     mysql  dateFormat = Y-m-d H:i:s
 *
 * La colonne ne retient donc pas la sous-seconde, et un condensat de la meme
 * colonne aurait la meme cecite. La granularite est une propriete du stockage,
 * pas du marqueur.
 *
 * CE QU'IL DIVULGUE : l'instant de la derniere modification, a la seconde. Ces
 * pages s'ouvrent sans compte et `created_at` est deja expose par
 * VehicleResource ; la date a laquelle une annonce a ete retouchee n'est pas du
 * meme ordre qu'un prix d'achat ou qu'un fournisseur — rien de la frontiere que
 * garde partage/article.blade.php ne passe par ici.
 */
final class VersionPartage
{
    /**
     * Le marqueur d'un article, ou null quand il n'y en a pas.
     *
     * Null est une reponse PREVUE par le contrat, pas un echec : l'application
     * et la page retombent alors sur l'adresse nue, qui reste valable — elle
     * perd seulement le rafraichissement de vignette.
     *
     * Deux cas la rendent. `updated_at` nul, d'abord : la colonne est nullable
     * (c'est ce que pose $table->timestamps()) et une ligne inseree en SQL brut
     * peut la laisser vide. Un instant anterieur a l'epoque, ensuite :
     * base_convert ne prend pas de signe et rendrait une chaine fausse mais
     * d'apparence normale. Ce second cas ne peut venir que d'une ecriture
     * manuelle — Eloquent pose now() — mais c'est lui qui rend vraie la
     * monotonie affirmee plus haut, et une affirmation qui depend d'un cas non
     * traite n'est pas une affirmation.
     */
    public static function pour(?DateTimeInterface $modifieLe): ?string
    {
        if ($modifieLe === null) {
            return null;
        }

        $instant = $modifieLe->getTimestamp();

        if ($instant <= 0) {
            return null;
        }

        return base_convert((string) $instant, 10, 36);
    }
}
