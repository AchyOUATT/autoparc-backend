<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrimsSeeder extends Seeder
{
    public function run(): void
    {
        $modelMap    = DB::table('vehicle_models')->get()->keyBy(fn($m) => $m->brand_id . '_' . $m->slug);
        $brandMap    = DB::table('brands')->pluck('id', 'slug');
        $engineMap   = DB::table('engine_types')->pluck('id', 'code');
        $driveMap    = DB::table('drivetrains')->pluck('id', 'code');

        // Résolution: brand_slug + model_slug → model_id
        $resolve = function (string $brandSlug, string $modelSlug) use ($brandMap, $modelMap): ?int {
            $brandId = $brandMap[$brandSlug] ?? null;
            if (! $brandId) return null;
            $key = $brandId . '_' . $modelSlug;
            return $modelMap[$key]->id ?? null;
        };

        $petrol   = $engineMap['petrol']   ?? null;
        $diesel   = $engineMap['diesel']   ?? null;
        $hybrid   = $engineMap['hybrid']   ?? null;
        $electric = $engineMap['electric'] ?? null;
        $fwd = $driveMap['FWD'] ?? null;
        $rwd = $driveMap['RWD'] ?? null;
        $awd = $driveMap['AWD'] ?? null;
        $dwa = $driveMap['4WD'] ?? null;

        // Structure: [brand_slug, model_slug, name, code, rank, engine_type, drivetrain, power_hp, cc, cylinders, tx, description?]
        // tx: 'manual' | 'automatic' | 'cvt'
        //
        // SEULES SIX colonnes atteignent la base : name, code, rank,
        // default_engine_type_id, default_drivetrain_id — et, depuis le lot
        // Lexus, description. La puissance, la cylindree, le nombre de
        // cylindres et la boite ne sont PAS stockes : ce sont des commentaires
        // structures, qui disent d'ou vient la ligne et ce qu'elle designe.
        //
        // La douzieme colonne, `description`, est FACULTATIVE : les lignes
        // ecrites avant le lot Lexus n'en portent pas et restent valides —
        // la boucle en bas lit `$ligne[11] ?? null`. En base la colonne est
        // `text` nullable depuis la migration de creation des tables, donc
        // aucune de ces lignes n'a besoin d'etre touchee.
        $trims = [
            // ── TOYOTA COROLLA E210 ──────────────────────────────────────
            ['toyota', 'corolla-e210', 'LE',       'LE',   1, $petrol, $fwd, 139, 1987, 4, 'cvt'],
            ['toyota', 'corolla-e210', 'SE',       'SE',   2, $petrol, $fwd, 169, 2487, 4, 'cvt'],
            ['toyota', 'corolla-e210', 'XLE',      'XLE',  3, $petrol, $fwd, 169, 2487, 4, 'cvt'],
            ['toyota', 'corolla-e210', 'XSE',      'XSE',  4, $petrol, $fwd, 169, 2487, 4, 'automatic'],

            // ── TOYOTA COROLLA E180 ──────────────────────────────────────
            ['toyota', 'corolla-e180', 'J',        'J',    1, $petrol, $fwd, 97,  1329, 4, 'manual'],
            ['toyota', 'corolla-e180', 'L',        'L',    2, $petrol, $fwd, 132, 1798, 4, 'manual'],
            ['toyota', 'corolla-e180', 'GL',       'GL',   3, $petrol, $fwd, 132, 1798, 4, 'automatic'],
            ['toyota', 'corolla-e180', 'GLi',      'GLi',  4, $petrol, $fwd, 132, 1798, 4, 'automatic'],
            // La S manquait, signalee par le proprietaire sur un cas reel.
            //
            // Les quatre au-dessus — J, GL, GLi — sont des noms de marche
            // GOLFE / ASIE / AFRIQUE, alors que la E210 juste au-dessus porte
            // LE, SE, XLE, XSE, qui sont nord-americains. Ce fichier melange
            // donc deux nomenclatures selon la generation, et c'est ce melange
            // qui laisse des trous : un vehicule importe d'Amerique du Nord ne
            // trouve pas sa finition sur la E180, alors qu'il la trouverait sur
            // la E210.
            //
            // La S est la finition sportive nord-americaine de cette
            // generation : meme 1.8 2ZR-FE que la L et la GL, 132 ch, boite a
            // variation continue. Le reste de la gamme nord-americaine — LE,
            // LE Eco, XLE, XSE, SE — manque toujours et n'est pas pose ici :
            // le proprietaire n'a demande que la S, et une finition inventee se
            // retrouve sur une annonce.
            ['toyota', 'corolla-e180', 'S',        'S',    5, $petrol, $fwd, 132, 1798, 4, 'cvt'],

            // ── TOYOTA HILUX AN120 ───────────────────────────────────────
            ['toyota', 'hilux-an120', 'J',         'J',    1, $diesel, $dwa, 110, 2393, 4, 'manual'],
            ['toyota', 'hilux-an120', 'E',         'E',    2, $diesel, $dwa, 150, 2393, 4, 'manual'],
            ['toyota', 'hilux-an120', 'G',         'G',    3, $diesel, $dwa, 150, 2393, 4, 'automatic'],
            ['toyota', 'hilux-an120', 'GR-S',      'GRS',  4, $petrol, $dwa, 278, 3456, 6, 'automatic'],

            // ── TOYOTA LAND CRUISER J200 ─────────────────────────────────
            ['toyota', 'land-cruiser-j200', 'GX',  'GX',   1, $diesel, $dwa, 204, 4461, 8, 'automatic'],
            ['toyota', 'land-cruiser-j200', 'VX',  'VX',   2, $diesel, $dwa, 204, 4461, 8, 'automatic'],
            ['toyota', 'land-cruiser-j200', 'VX-R','VXR',  3, $petrol, $dwa, 318, 4608, 8, 'automatic'],

            // ── TOYOTA LAND CRUISER PRADO J150 ───────────────────────────
            ['toyota', 'land-cruiser-prado-j150', 'GX',   'GX',  1, $diesel, $dwa, 177, 2755, 4, 'manual'],
            ['toyota', 'land-cruiser-prado-j150', 'GXL',  'GXL', 2, $diesel, $dwa, 177, 2755, 4, 'automatic'],
            ['toyota', 'land-cruiser-prado-j150', 'TXL',  'TXL', 3, $petrol, $dwa, 270, 3956, 6, 'automatic'],
            ['toyota', 'land-cruiser-prado-j150', 'VXL',  'VXL', 4, $petrol, $dwa, 270, 3956, 6, 'automatic'],

            // ── TOYOTA FORTUNER AN160 ────────────────────────────────────
            ['toyota', 'fortuner-an160', 'GX',     'GX',   1, $diesel, $rwd, 150, 2393, 4, 'manual'],
            ['toyota', 'fortuner-an160', 'GXR',    'GXR',  2, $diesel, $rwd, 204, 2755, 4, 'automatic'],
            ['toyota', 'fortuner-an160', 'VXR',    'VXR',  3, $diesel, $dwa, 204, 2755, 4, 'automatic'],
            ['toyota', 'fortuner-an160', 'Legender','LEG',  4, $petrol, $awd, 231, 2694, 4, 'automatic'],

            // ── TOYOTA RAV4 XA50 ─────────────────────────────────────────
            ['toyota', 'rav4-xa50', 'LE',          'LE',   1, $petrol, $fwd, 203, 2487, 4, 'automatic'],
            ['toyota', 'rav4-xa50', 'XLE',         'XLE',  2, $petrol, $fwd, 203, 2487, 4, 'automatic'],
            ['toyota', 'rav4-xa50', 'TRD Off-Road','TRD',  3, $petrol, $awd, 203, 2487, 4, 'automatic'],
            ['toyota', 'rav4-xa50', 'Hybrid XSE',  'HYB',  4, $hybrid, $awd, 219, 2487, 4, 'cvt'],

            // ── TOYOTA YARIS XP210 ───────────────────────────────────────
            ['toyota', 'yaris-xp210', 'E',         'E',    1, $petrol, $fwd, 72,  998,  3, 'manual'],
            ['toyota', 'yaris-xp210', 'J',         'J',    2, $petrol, $fwd, 72,  998,  3, 'cvt'],
            ['toyota', 'yaris-xp210', 'G',         'G',    3, $petrol, $fwd, 116, 1490, 3, 'cvt'],
            ['toyota', 'yaris-xp210', 'GR Sport',  'GR',   4, $hybrid, $fwd, 115, 1490, 3, 'cvt'],

            // ══ TOYOTA : SIX GENERATIONS NORD-AMERICAINES RESTEES VIDES ══
            //
            // MESURE AVANT CE LOT, sur base jetable (sqlite, pays et marques
            // semes d'abord, puis VehicleModelsSeeder et TrimsSeeder) : la
            // marque toyota porte 29 modeles, dont 21 SANS AUCUNE FINITION,
            // pour 32 finitions au total. Les huit pourvues sont les plus
            // recentes ; les vides sont justement les generations qui
            // s'importent d'occasion. Ce lot en pose SIX et argumente les
            // quinze autres, refus compris.
            //
            // ─── LA QUESTION TRANCHEE : QUELLE NOMENCLATURE ? ───────────
            //
            // Le fichier melangeait deja deux conventions sans le dire. La
            // corolla-e180 porte J, L, GL, GLi — noms du Golfe, d'Asie et
            // d'Afrique — plus une S nord-americaine ; la corolla-e210 juste
            // au-dessus porte LE, SE, XLE, XSE, qui sont nord-americains.
            //
            // REGLE RETENUE : une generation recoit la nomenclature du marche
            // dont la gamme est VERIFIABLE sur deux sources independantes pour
            // CETTE generation-la. Quand deux marches le sont, on pose les
            // deux, et le nom ou la description porte le marche.
            //
            // Ce n'est pas un choix de confort, c'est le resultat d'une
            // recherche qui a reussi d'un cote et echoue de l'autre. La gamme
            // nord-americaine se publie millesime par millesime, communiques
            // constructeur a l'appui. La gamme du Golfe des generations
            // anciennes, elle, ne se retrouve que sur des sites d'annonces
            // entre particuliers. Le site officiel Toyota Emirats arabes unis,
            // consulte pour ce lot le 20/10/2026, ne nomme AUCUNE finition
            // pour le Land Cruiser : il vend un « Land Cruiser 3.5L » de
            // 409 ch et un « Land Cruiser 4.0L » de 271 ch, deux cylindrees
            // et pas un GXR.
            //   D'ou le resultat : les six generations posees ici sont toutes
            //   nord-americaines. Les 4x4 et les pick-up du Golfe sont
            //   RAPPORTES, pas devines.
            //
            // ─── CE QUI EST ECARTE, ET POURQUOI, EN UNE LIGNE ───────────
            //
            // hilux-an10, fortuner-an50, land-cruiser-j100,
            //   land-cruiser-prado-j120, land-cruiser-j300 : les codes moteur
            //   sont surs des deux cotes, la GAMME du Golfe ne l'est pas — un
            //   GXR de 2007 ne se recoupe que sur des annonces.
            // avensis-t270, auris-e180 : en Europe le nom de finition change
            //   de PAYS en pays — Active / Icon / Sport / Excel au Royaume-Uni
            //   d'apres le communique Toyota GB du 26/09/2012, Tendance /
            //   Dynamic / Design / Executive en France — et le Royaume-Uni
            //   roule a droite, donc ce n'est pas de la que vient le parc.
            // yaris-xp150 : l'entree elle-meme est douteuse, voir ci-dessous.
            // vios, rush-f800 : gammes d'Asie du Sud-Est, et le slug `vios`
            //   est nu, voir ci-dessous.
            // highlander, sienna, venza, avalon, tacoma : pas de generation,
            //   voir ci-dessous. Rien pose, c'est une decision du proprietaire.
            //
            // ─── TROIS CHOSES A RAPPORTER, AUCUNE CORRIGEE ICI ──────────
            //
            // 1. `yaris-xp150` EST PROBABLEMENT UNE XP130. Le referentiel la
            //    donne citadine, 2011-2020. Or Wikipedia anglais separe les
            //    deux codes : la XP130 est la citadine a hayon de 2011, vendue
            //    en Europe, au Japon, au Canada, en Afrique du Sud et aux
            //    Etats-Unis jusqu'en 2018-2020 ; la XP150 est la Yaris d'Asie
            //    de 2013 et la berline tricorps derivee de la Vios. Le
            //    millesime 2011 et la carrosserie citadine designent donc la
            //    XP130, pas la XP150. Le catalogue de filtres du depot porte
            //    la meme lecture — sa ligne yaris-xp150 justifie sa borne
            //    basse par « debut de la generation XP150 » en 2011. Les deux
            //    se trompent ensemble, ou bien le slug veut dire XP130 et le
            //    commentaire du catalogue est a reecrire. Poser des finitions
            //    la-dessus figerait le malentendu : rien n'est pose.
            //
            // 2. `vios` A UN SLUG NU, et cela ne veut pas dire « une seule
            //    generation ». Ses bornes 2013-ouvertes couvrent la XP150
            //    (2013-2023) ET la XP180 lancee en 2022, qui ne portent pas la
            //    meme gamme. Une finition posee sur ce slug vaudrait pour les
            //    deux sans pouvoir le dire. S'y ajoute que la Vios construite
            //    en Indonesie part au Golfe sous le nom de YARIS BERLINE : un
            //    vehicule importe ici porterait un nom que le referentiel
            //    range ailleurs.
            //
            // 3. LES CINQ IMPORTATIONS AMERICAINES N'ONT PAS DE GENERATION,
            //    ET C'EST BLOQUANT. Highlander 2008-ouvert couvre XU40, XU50
            //    et XU70 ; Sienna 2007-ouvert couvre XL20, XL30 et XL40 ;
            //    Venza 2009-ouvert couvre AV10 et XU80 ; Avalon 2005-2022
            //    couvre XX30, XX40 et XX50 ; Tacoma 1995-ouvert en couvre
            //    quatre. Les gammes ne se recouvrent pas : la Highlander XU40
            //    se vendait en Base, Sport puis SE, et Limited ; la XU70 se
            //    vend en L, LE, XLE, XSE, Limited et Platinum. Deux jeux qui
            //    ne se rejoignent que sur « Limited ». Poser « LE » sur ce
            //    slug proposerait cette finition a une Highlander de 2010,
            //    qui ne l'a jamais portee — exactement ce que le commentaire
            //    de la corolla-e180 redoute plus haut dans ce fichier,
            //    « une finition inventee se retrouve sur une annonce ».
            //      Et la description ne rattraperait rien : une « Limited »
            //      Venza est un V6 3,5 2GR-FE de 268 ch jusqu'en 2015 et une
            //      hybride 2,5 A25A-FXS integrale a partir de 2021. Une
            //      description qui nommerait un moteur serait fausse sur la
            //      moitie de la plage ; une description qui les nommerait tous
            //      les deux ne dirait plus rien.
            //      DEUX SORTIES EXISTENT, et les deux sont des decisions de
            //      catalogue qui reviennent au proprietaire : decouper les
            //      generations — ce que `VehicleModelsSeeder` dit lui-meme ne
            //      pas avoir ete decide — ou qualifier le nom, comme la
            //      « 528i (4 cyl. 2011-2016) » du lot BMW le fait deja dans ce
            //      fichier. Rien n'est pose tant que l'une des deux n'est pas
            //      choisie.
            //
            // ─── LE CATALOGUE DE FILTRES DU DEPOT, SOURCE INTERNE ───────
            //
            // `FiltresHuileBoutiqueSeeder` nomme le code moteur des six
            // generations posees ici, et il les CONFIRME toutes : 1ZZ-FE puis
            // 2ZR-FE et 2AZ-FE sur corolla-e140, 2AR-FE / 2AR-FXE / 2GR-FE sur
            // camry-xv50, A25A-FKS / A25A-FXS / 2GR-FKS sur camry-xv70, 2AR-FE
            // et 2AR-FXE sur rav4-xa40, 2ZR-FXE sur les deux Prius. Aucune
            // contradiction relevee ; les accords les plus utiles sont nommes
            // generation par generation ci-dessous.
            //   UN POINT QUI AURAIT PU PASSER POUR UNE CONTRADICTION : le
            //   catalogue date le 2AZ-FE de la Corolla de 2009 a 2013, alors
            //   que la XRS quitte le catalogue AMERICAIN apres le millesime
            //   2010 — Wikipedia anglais le dit, et Toyota Canada la vend
            //   encore au millesime 2011. Ce n'est pas un desaccord : la
            //   ligne du catalogue nomme « Corolla XRS, Matrix, Matrix XRS »,
            //   et c'est la Matrix qui tient jusqu'en 2013.
            //
            // LA MOTRICITE PAR DEFAUT NE CACHE AUCUN FILTRE ICI, et cela
            // s'oppose a ce que le lot Lexus a mesure sur ses propres lignes.
            // Compte refait pour ce lot sur `FiltresHuileBoutiqueSeeder` :
            // QUATORZE des 94 compatibilites seulement portent un code de
            // motricite, et les quatorze sont des Lexus : is-xe20, is-xe30,
            // gs-s190, gs-l10 et rc-xc10. Toute ligne Toyota a son
            // `drivetrain_id` nul, et `CompatibilityService` filtre sur
            // « drivetrain_id IS NULL OR = X ». Poser FWD ou AWD sur ces
            // finitions epargne donc une saisie au vendeur sans rien masquer.
            //
            // MESURE FAITE SUR BASE JETABLE, et elle vaut pour tout le lot :
            // pour chacune des six finitions interrogees — camry-xv50 « LE »
            // 2014, camry-xv70 « XLE V6 » 2020, rav4-xa40 « Hybrid XLE » 2017,
            // corolla-e140 « XRS » 2011, prius-xw30 « Two » 2013 et
            // prius-xw50 « XLE » 2020 — `partsForCriteria()` rend EXACTEMENT
            // les memes references avec et sans la motricite. Le defaut pose
            // ici n'ouvre ni ne ferme aucune porte, il epargne une saisie.
            //
            // CE QUE LA MEME MESURE MONTRE EN REVANCHE, et qu'il faut savoir :
            // une « XRS » de 2011 recoit la 90915-YZZF1, qui est la sienne —
            // c'est la reference du 2AZ-FE — MAIS AUSSI la 04152-YZZA6, qui
            // est la cartouche du 1,8. Une « XLE V6 » de Camry XV70 recoit de
            // meme la 04152-YZZA1 de son V6 et la 90915-YZZN1 du quatre
            // cylindres. La raison est celle que le lot Lexus a deja ecrite :
            // une finition ne porte PAS de code moteur, et la motricite
            // n'affine pas ici puisque les lignes Toyota n'en portent pas non
            // plus. C'est une proposition, pas une prescription, et c'est la
            // description qui dit au vendeur laquelle prendre.
            //
            // LE RANG va du plus modeste au plus puissant, comme ailleurs.
            // Deux endroits ou la puissance ne departage pas : les quatre
            // Prius d'une meme generation partagent la chaine hybride au
            // cheval pres, et la Camry XV70 « XLE V6 » et « TRD » rendent les
            // memes 301 ch. Le rang y suit le niveau d'equipement, et c'est
            // dit ici plutot que laisse deviner.
            //
            // SOURCES. Chaque designation, chaque code moteur et chaque
            // motricite est recoupe sur deux sources independantes au moins,
            // nommees generation par generation ci-dessous, plus le catalogue
            // de filtres du depot partout ou il parle. Les divergences
            // retenues sont nommees a l'endroit ou elles tombent.

            // ── TOYOTA COROLLA E140 (Amerique du Nord 2009-2013) ─────────
            // Sources : Wikipedia anglais « Toyota Corolla (E140) », qui donne
            // la gamme americaine Base / S / LE / XLE / XRS et la gamme
            // canadienne CE / LE / Sport ou S / XRS, le 2ZR-FE 1798 a 132 ch
            // et le 2AZ-FE 2362 a 158 ch ; et le communique Toyota Canada
            // « What's new: 2011 Toyota Corolla », qui donne les QUATRE memes
            // finitions canadiennes, 1,8 l sur CE, LE et S, 2,4 l de 158 ch
            // sur la seule XRS. Les quatre posees sont celles que les DEUX
            // sources nomment.
            //   La XLE americaine n'est pas posee : une seule source la nomme.
            //   La base americaine non plus — elle s'appelle « Corolla » tout
            //   court jusqu'en 2010 puis « L », et trancher demanderait une
            //   source que je n'ai pas.
            //
            // DIVERGENCE DE PUISSANCE, ET ELLE EST REELLE : Toyota Canada
            // annonce 138 ch pour le 1,8, Wikipedia 132 ch pour l'Amerique du
            // Nord. 132 est retenu — c'est la valeur que portent deja les
            // lignes 2ZR-FE de la corolla-e180 dans ce fichier, et six chevaux
            // d'ecart sur le meme moteur sentent la norme de mesure plutot que
            // la mecanique. Valeur non stockee de toute facon.
            //
            // LE CATALOGUE DE FILTRES CONFIRME LA XRS NOMMEMENT : sa ligne
            // 2AZ-FE dit « Corolla XRS, Matrix, Matrix XRS 2.4 2AZ-FE
            // 2009-2013 », statut « Confirmé », doublement corroboree par
            // l'index par code moteur. C'est la seule finition de ce lot que
            // le depot nomme lui-meme.
            ['toyota', 'corolla-e140', 'CE',  'CE',  1, $petrol, $fwd, 132, 1798, 4, 'manual',    '1,8 4 cylindres 2ZR-FE, 132 ch, traction — entrée de gamme canadienne'],
            ['toyota', 'corolla-e140', 'LE',  'LE',  2, $petrol, $fwd, 132, 1798, 4, 'automatic', '1,8 4 cylindres 2ZR-FE, 132 ch, traction — gamme nord-américaine'],
            ['toyota', 'corolla-e140', 'S',   'S',   3, $petrol, $fwd, 132, 1798, 4, 'automatic', '1,8 4 cylindres 2ZR-FE, 132 ch, traction — finition sportive, même moteur que la LE'],
            ['toyota', 'corolla-e140', 'XRS', 'XRS', 4, $petrol, $fwd, 158, 2362, 4, 'manual',    '2,4 4 cylindres 2AZ-FE, 158 ch, traction — la seule E140 nord-américaine qui ne soit pas un 1,8'],

            // ── TOYOTA CAMRY XV50 (2011-2017) ────────────────────────────
            // Sources : Wikipedia anglais « Toyota Camry (XV50) », qui donne
            // la gamme nord-americaine L / LE / SE / XLE, les versions V6 et
            // les hybrides, le 2AR-FE a 177-181 ch, le 2GR-FE V6 a 268 ch et
            // le 2AR-FXE hybride a 200 ch cumules ; et les fiches Cars.com
            // « 2015 Toyota Camry » — LE, SE, XLE, XSE, XLE V6, XSE V6, 2,5 l
            // de 178 ch, V6 de 268 ch, boite a six rapports, traction — et
            // « 2016 Toyota Camry Hybrid » — LE, SE, XLE, 200 ch cumules,
            // traction.
            //
            // DIVERGENCE TRANCHEE : Cars.com appelle l'hybride « 2.4-liter ».
            // Deux sources disent le contraire — Wikipedia donne 2,5 l, et le
            // catalogue de filtres du depot ecrit « Camry hybride 2.5 2AR-FXE
            // 2012-2017 FWD », statut « Confirme ». Le 2,5 est retenu.
            //
            // LE CATALOGUE DE FILTRES COUVRE LES TROIS MOTEURS de cette
            // generation : 2AR-FE 2010-2017 en traction, 2AR-FXE hybride
            // 2012-2017 et 2GR-FE avec sa borne basse remontee a 2011, debut
            // de la XV50. Aucune de ces lignes ne porte de motricite, donc la
            // traction posee ici ne masque aucune reference.
            //
            // La XSE n'arrive qu'au restylage de 2015, et la description le
            // dit : sans cela le nom laisserait croire a une version presente
            // sur toute la generation.
            ['toyota', 'camry-xv50', 'LE',        'LE',   1, $petrol, $fwd, 178, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 178 ch, traction — gamme nord-américaine'],
            ['toyota', 'camry-xv50', 'SE',        'SE',   2, $petrol, $fwd, 178, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 178 ch, traction — finition sportive nord-américaine'],
            ['toyota', 'camry-xv50', 'XLE',       'XLE',  3, $petrol, $fwd, 178, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 178 ch, traction — finition haute nord-américaine'],
            ['toyota', 'camry-xv50', 'XSE',       'XSE',  4, $petrol, $fwd, 178, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 178 ch, traction — apparue au restylage de 2015, absente avant'],
            ['toyota', 'camry-xv50', 'Hybrid LE', 'HLE',  5, $hybrid, $fwd, 200, 2494, 4, 'cvt',       '2,5 4 cylindres hybride 2AR-FXE, 200 ch cumulés, traction'],
            ['toyota', 'camry-xv50', 'XLE V6',    'XLE6', 6, $petrol, $fwd, 268, 3456, 6, 'automatic', '3,5 V6 2GR-FE, 268 ch, traction — rien du 2,5 que porte la XLE quatre cylindres'],

            // ── TOYOTA CAMRY XV70 (2017→) ────────────────────────────────
            // Sources : Wikipedia anglais « Toyota Camry (XV70) », qui donne
            // la gamme L / LE / SE / XLE / XSE, les versions V6, la TRD
            // apparue au millesime 2020 et reservee au V6, l'A25A-FKS a 203 ou
            // 206 ch selon la version et 202 ch en integrale, l'A25A-FXS
            // hybride et le 2GR-FKS a 301 ch ; et la fiche Cars.com « 2020
            // Toyota Camry » — L, LE, SE, XLE, XSE, XLE V6, XSE V6, TRD V6,
            // integrale offerte sur les quatre cylindres et pas sur le V6, V6
            // a 301 ch — doublee de « 2020 Toyota Camry Hybrid », qui donne LE,
            // SE et XLE en traction et eCVT.
            //
            // LES 203 CONTRE 206 CH NE SONT PAS UNE RECOPIE DE LA LIGNE
            // VOISINE : le meme A25A-FKS rend 203 ch sur LE et XLE et 206 ch
            // sur la XSE, qui a la double sortie d'echappement. Les deux
            // valeurs sont posees telles quelles, et c'est pour cela que la
            // XSE passe devant la XLE au rang.
            //
            // L'INTEGRALE EXISTE SUR CETTE GENERATION depuis le millesime
            // 2020, mais sur les quatre cylindres seulement. La traction reste
            // le defaut ; la description nomme l'autre montage la ou il
            // existe, comme le lot Lexus le fait.
            //
            // LA LIGNE HYBRIDE PORTE 208 CH EN COLONNE ET 176 EN DESCRIPTION,
            // et ce n'est pas une incoherence : 176 ch est le thermique seul,
            // donne par le tableau de Wikipedia et confirme par les fiches de
            // millesime 2020 ; 208 ch est la puissance cumulee, que seul le
            // corps du meme article donne. La description ne retient donc que
            // le chiffre a deux sources, et la colonne — qui n'atteint pas la
            // base — porte le cumul pour que le rang reste comparable.
            //
            // LE CATALOGUE DE FILTRES CONFIRME les trois moteurs : A25A-FKS et
            // A25A-FXS a partir de 2018, 2GR-FKS 2018-2024 en traction. Le
            // document s'en sert meme d'exemple, la meme XV70 prenant la
            // cartouche avec le V6 et le filtre visse avec le quatre
            // cylindres.
            //
            // LA TRD PARTAGE LES 301 CH DE LA XLE V6 : son rang la met apres,
            // sur l'equipement et non sur la puissance, faute de pouvoir les
            // departager autrement.
            //
            // LA CYLINDREE 2487 N'EST PAS CELLE DE WIKIPEDIA, qui arrondit
            // l'A25A a 2500 cm3 : 2487 est la valeur que portent deja les
            // lignes A25A-FXS posees par le lot Lexus dans ce fichier, et la
            // coherence interne l'emporte sur un arrondi. Colonne non stockee.
            //
            // « HYBRID LE » PORTE UN NOM COMPOSE PARCE QUE LA BASE L'IMPOSE :
            // `trims` est unique sur (vehicle_model_id, name), et « LE » est
            // deja pris par la thermique. Les fiches de millesime 2020
            // l'ecrivent d'ailleurs ainsi.
            ['toyota', 'camry-xv70', 'LE',        'LE',   1, $petrol, $fwd, 203, 2487, 4, 'automatic', '2,5 4 cylindres A25A-FKS, 203 ch en traction et 202 en intégrale, intégrale offerte à partir du millésime 2020'],
            ['toyota', 'camry-xv70', 'SE',        'SE',   2, $petrol, $fwd, 203, 2487, 4, 'automatic', '2,5 4 cylindres A25A-FKS, 203 ch en traction et 202 en intégrale, intégrale offerte à partir du millésime 2020'],
            ['toyota', 'camry-xv70', 'XLE',       'XLE',  3, $petrol, $fwd, 203, 2487, 4, 'automatic', '2,5 4 cylindres A25A-FKS, 203 ch en traction et 202 en intégrale, intégrale offerte à partir du millésime 2020'],
            ['toyota', 'camry-xv70', 'XSE',       'XSE',  4, $petrol, $fwd, 206, 2487, 4, 'automatic', '2,5 4 cylindres A25A-FKS, 206 ch grâce à la double sortie, traction ou intégrale à partir du millésime 2020'],
            ['toyota', 'camry-xv70', 'Hybrid LE', 'HLE',  5, $hybrid, $fwd, 208, 2487, 4, 'cvt',       '2,5 4 cylindres hybride A25A-FXS, 176 ch pour le thermique seul, traction'],
            ['toyota', 'camry-xv70', 'XLE V6',    'XLE6', 6, $petrol, $fwd, 301, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 301 ch, traction seule, jamais proposé en intégrale'],
            ['toyota', 'camry-xv70', 'TRD',       'TRD',  7, $petrol, $fwd, 301, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 301 ch, traction — dérivée de la SE, V6 seulement, à partir du millésime 2020'],

            // ── TOYOTA RAV4 XA40 (2012-2018) ─────────────────────────────
            // Sources : Wikipedia anglais « Toyota RAV4 », section quatrieme
            // generation, qui donne le 2AR-FE 2494 a 176 ch, l'hybride
            // 2AR-FXE a 194 ch cumules avec son moteur arriere de 50 kW, et
            // nomme LE, XLE et Limited pour l'hybride americaine ; et la fiche
            // Cars.com « 2016 Toyota RAV4 » — LE, XLE, SE, Limited en 2,5 l de
            // 176 ch, boite a six rapports, traction ou integrale ; hybrides
            // XLE et Limited en INTEGRALE SEULE.
            //
            // L'HYBRIDE EST INTEGRALE ET RIEN D'AUTRE en Amerique du Nord, et
            // c'est le catalogue de filtres du depot qui fait la troisieme
            // source : sa ligne dit « RAV4 hybride 2.5 2AR-FXE 2016-2018 AWD »,
            // statut « Confirme ». Les trois concordent.
            //
            // LA « HYBRID LE » N'EST PAS POSEE : Wikipedia la nomme au
            // lancement americain, Cars.com ne donne que XLE et Limited au
            // millesime 2016. Une source contre une ne suffit pas.
            //
            // Le catalogue donne aussi le 2AR-FE en 2009-2018 pour ce RAV4,
            // borne large qui deborde la generation des deux cotes parce
            // qu'elle vient du document fournisseur ; il ne porte pas de
            // motricite sur cette ligne, donc la traction posee sur les
            // thermiques ne masque aucune reference.
            ['toyota', 'rav4-xa40', 'LE',             'LE',  1, $petrol, $fwd, 176, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 176 ch, traction ou intégrale — gamme nord-américaine'],
            ['toyota', 'rav4-xa40', 'XLE',            'XLE', 2, $petrol, $fwd, 176, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 176 ch, traction ou intégrale'],
            ['toyota', 'rav4-xa40', 'SE',             'SE',  3, $petrol, $fwd, 176, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 176 ch, traction ou intégrale — suspension sport, même moteur ; à partir du millésime 2016'],
            ['toyota', 'rav4-xa40', 'Limited',        'LTD', 4, $petrol, $fwd, 176, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 176 ch, traction ou intégrale — finition haute'],
            ['toyota', 'rav4-xa40', 'Hybrid XLE',     'HXL', 5, $hybrid, $awd, 194, 2494, 4, 'cvt',       '2,5 4 cylindres hybride 2AR-FXE, 194 ch cumulés, intégrale seule par un moteur arrière électrique ; à partir du millésime 2016'],
            ['toyota', 'rav4-xa40', 'Hybrid Limited', 'HLT', 6, $hybrid, $awd, 194, 2494, 4, 'cvt',       '2,5 4 cylindres hybride 2AR-FXE, 194 ch cumulés, intégrale seule par un moteur arrière électrique ; à partir du millésime 2016'],

            // ── TOYOTA PRIUS XW30 (2009-2015) ────────────────────────────
            // Sources : Wikipedia anglais « Toyota Prius (XW30) », qui donne
            // les finitions americaines Two, Three, Four et Five, le 2ZR-FXE,
            // 98 ch pour le thermique, 134 ch cumules et la traction ; et la
            // fiche Cars.com « 2013 Toyota Prius », qui nomme One, Two, Three,
            // Four, Five et Persona, toutes en traction.
            //   One et Persona ne sont pas posees : une seule des deux sources
            //   les nomme.
            //
            // LE NOM NE DIT RIEN DU MOTEUR, ET POUR UNE FOIS C'EST PARCE QU'IL
            // N'Y EN A QU'UN : les quatre niveaux partagent le meme 2ZR-FXE et
            // la meme chaine hybride, seul l'equipement change. La description
            // le dit, sinon un vendeur chercherait une difference mecanique
            // qui n'existe pas. Le rang suit donc l'equipement et non la
            // puissance — c'est la premiere des deux exceptions annoncees en
            // tete de lot.
            //
            // Le catalogue de filtres du depot pose cette generation en
            // 2ZR-FXE de 2010 a 2015, tableau YZZA6, et dit explicitement
            // avoir borne sa plage « a la generation XW30 » — un an apres le
            // debut de la generation au referentiel, ce que le document ne
            // s'explique pas et qui ne change rien a ce qui est pose ici.
            ['toyota', 'prius-xw30', 'Two',   'TWO',  1, $hybrid, $fwd, 134, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 98 ch thermiques et 134 ch cumulés, traction'],
            ['toyota', 'prius-xw30', 'Three', 'THR',  2, $hybrid, $fwd, 134, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 134 ch cumulés, traction — mécanique identique à la Two'],
            ['toyota', 'prius-xw30', 'Four',  'FOUR', 3, $hybrid, $fwd, 134, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 134 ch cumulés, traction — mécanique identique à la Two'],
            ['toyota', 'prius-xw30', 'Five',  'FIVE', 4, $hybrid, $fwd, 134, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 134 ch cumulés, traction — mécanique identique à la Two'],

            // ── TOYOTA PRIUS XW50 (2015-2022) ────────────────────────────
            // Sources : Wikipedia anglais « Toyota Prius (XW50) », qui ecrit
            // textuellement qu'en 2019 les finitions americaines « One, Two,
            // Three et Four » sont renommees « L, LE, XLE et Limited », et qui
            // donne le 2ZR-FXE, la traction et l'AWD-e a moteur arriere de
            // 5 kW ; et la fiche Cars.com « 2018 Toyota Prius » — One, Two,
            // Two Eco, Three, Three Touring, Four, Four Touring, toutes en
            // traction. La puissance cumulee de 121 ch vient de la fiche
            // Kelley Blue Book du millesime 2018 et se recoupe avec les
            // 89,5 kW de la fiche Carsales du meme millesime ; la cylindree de
            // 1798 cm3 et le code 2ZR-FXE sont donnes par la fiche CarExpert.
            //
            // DEUX NOMENCLATURES SUR UNE SEULE GENERATION, ET C'EST POUR CELA
            // QUE LES SIX LIGNES COEXISTENT. Ce n'est pas un melange de
            // marches comme sur la Corolla, c'est une rupture de millesime :
            // la meme voiture s'appelle « Three » en 2018 et « XLE » en 2020.
            // Un vendeur qui a la voiture de 2020 ne trouverait pas « XLE »
            // si seule « Three » etait posee. Les deux jeux sont donc poses,
            // et chaque description porte sa periode — meme choix que les
            // deux lignes « 528i » du lot BMW, pour une raison voisine.
            //   Two Eco et L Eco ne sont pas posees : une source chacune.
            //
            // L'AWD-e N'EXISTE PAS AVANT LE MILLESIME 2019 aux Etats-Unis : la
            // traction est le defaut partout, et la description ne nomme
            // l'integrale que sur les lignes ou elle existe.
            //
            // Le catalogue de filtres du depot ne couvre cette generation que
            // de 2018 a 2022, et il dit pourquoi : la XW50 traverse le passage
            // de la cartouche au filtre visse, et le document ne confirme que
            // la seconde moitie. Ce n'est pas une contradiction avec ce qui
            // est pose ici, qui ne parle pas de filtres.
            ['toyota', 'prius-xw50', 'Two',     'TWO',  1, $hybrid, $fwd, 121, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 121 ch cumulés, traction — nom des millésimes 2016 à 2018'],
            ['toyota', 'prius-xw50', 'Three',   'THR',  2, $hybrid, $fwd, 121, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 121 ch cumulés, traction — nom des millésimes 2016 à 2018'],
            ['toyota', 'prius-xw50', 'Four',    'FOUR', 3, $hybrid, $fwd, 121, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 121 ch cumulés, traction — nom des millésimes 2016 à 2018'],
            ['toyota', 'prius-xw50', 'LE',      'LE',   4, $hybrid, $fwd, 121, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 121 ch cumulés, traction ou AWD-e — la Two renommée au millésime 2019'],
            ['toyota', 'prius-xw50', 'XLE',     'XLE',  5, $hybrid, $fwd, 121, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 121 ch cumulés, traction ou AWD-e — la Three renommée au millésime 2019'],
            ['toyota', 'prius-xw50', 'Limited', 'LTD',  6, $hybrid, $fwd, 121, 1798, 4, 'cvt', '1,8 4 cylindres hybride 2ZR-FXE, 121 ch cumulés, traction — la Four renommée au millésime 2019'],

            // ══ TOYOTA, SECONDE MOITIE : LES GENERATIONS RESTEES VIDES ═══
            //
            // MESURE AVANT CE LOT, et pas reprise du lot precedent : base
            // jetable SQLite, pays et marques semes d'abord, puis
            // VehicleModelsSeeder, TrimsSeeder, FiltresHuileBoutiqueSeeder.
            // La marque toyota porte 33 lignes et 65 finitions, et DIX-NEUF
            // de ses lignes n'ont aucune finition.
            //
            // CE N'EST PLUS QUINZE, et c'est pour cela qu'il fallait
            // remesurer : le lot precedent a scinde le Tacoma en quatre
            // generations et renomme `yaris-xp150` en `yaris-xp130`.
            // 15 + 4 = 19. Le chiffre a bouge sous le lot.
            //
            // LES DIX-NEUF, telles que mesurees :
            //   generations nommees (13) : auris-e180, avensis-t270,
            //     fortuner-an50, hilux-an10, land-cruiser-j100,
            //     land-cruiser-j300, land-cruiser-prado-j120, rush-f800,
            //     tacoma-i, tacoma-ii, tacoma-iii, tacoma-iv, yaris-xp130
            //   slugs nus (6) : avalon, highlander, sienna, tacoma, venza,
            //     vios — `tacoma` etant en plus inactif.
            //
            // LES SIX SLUGS NUS NE RECOIVENT RIEN, et ce n'est pas un refus
            // faute de sources : une finition se pose sur une GENERATION.
            // Leurs bornes couvrent plusieurs generations aux gammes
            // disjointes — le point 3 plus haut dans ce fichier le detaille
            // pour Highlander, Sienna, Venza et Avalon, et il reste vrai
            // pour ces quatre. Y poser « LE » proposerait cette finition a
            // une Highlander de 2010, qui ne l'a jamais portee.
            //
            // ─── DEUX PASSAGES ONT VIEILLI PLUS HAUT DANS CE FICHIER ────
            //
            // Je ne les reecris pas — la consigne de ce lot interdit de
            // toucher une ligne existante — mais les laisser sans avis
            // tromperait le prochain lecteur, alors les voici nommes :
            //
            //   1. Le point 1 doute de `yaris-xp150` et conclut « rien n'est
            //      pose ». LE DOUTE A ETE TRANCHE depuis, par le lot
            //      precedent : le slug est devenu `yaris-xp130`, et ce lot-ci
            //      pose bien trois finitions dessus. Le soupcon etait juste,
            //      et la conclusion « ou bien le slug veut dire XP130 et le
            //      commentaire du catalogue est a reecrire » est celle qui a
            //      ete retenue.
            //   2. La ligne d'ecart « highlander, sienna, venza, avalon,
            //      tacoma : pas de generation » et le point 3 qui la
            //      developpe NE VALENT PLUS POUR LE TACOMA : il a recu ses
            //      quatre generations, sa ligne nue est desactivee, et deux
            //      de ces generations recoivent des finitions ci-dessous.
            //      Les quatre autres modeles restent nus, et pour eux le
            //      point 3 est intact.
            //
            // ─── ET UN TROISIEME A VIEILLI DEPUIS, PAR UN LOT SUIVANT ───
            //
            // Meme usage : rien n'est reecrit, le passage est nomme. Ce lot
            // de finitions etait deja ecrit quand il est arrive.
            //
            //   3. LE POINT 2 ET LE POINT 3 NE VALENT PLUS POUR LE VENZA.
            //      Le lot du 21/10/2026 qui a suivi celui-ci lui a pose ses
            //      deux generations — `venza-av10` (2008-2017) et
            //      `venza-xu80` (2020-) — et a vidé sa ligne nue comme celle
            //      du Tacoma. Le point 3 lisait « Venza 2009-ouvert couvre
            //      AV10 et XU80 » : c'etait exact, et c'est precisement ce
            //      qui a ete corrige.
            //
            //      CE LOT-CI N'EN A PAS POSE DE FINITION POUR AUTANT, et il
            //      ne faut pas le lire comme un refus : ses deux generations
            //      n'existaient pas quand il a mesure le referentiel. Elles
            //      sont donc sans gamme, et nommees dans
            //      `FinitionsToyotaSecondeMoitieTest::POSEES_APRES_CE_LOT`
            //      plutot que parmi les neuf ecartees — celles-la ont ete
            //      examinees, celles-ci pas encore.
            //
            //      Les TROIS modeles qui restent nus sont le Highlander, le
            //      Sienna et l'Avalon, et pour eux le point 3 est intact :
            //      sept de leurs compatibilites de filtres sont a cheval sur
            //      deux generations, et les trancher appartient au
            //      proprietaire. Le Venza n'en avait aucune, ce qui est la
            //      seule chose qui l'en separait.
            //
            // QUATRE GENERATIONS SUR LES TREIZE RECOIVENT DES FINITIONS.
            // NEUF SONT ECARTEES, chacune pour une raison nommee a sa place
            // ci-dessous. Vingt-trois finitions posees, `description`
            // remplie sur les vingt-trois.
            //
            // ─── POURQUOI AUCUNE MIGRATION N'ACCOMPAGNE CE LOT ──────────
            //
            // Le lot precedent avait besoin d'une migration parce qu'il
            // RENOMMAIT et DESACTIVAIT des lignes existantes, ce qu'un
            // upsert sur (brand_id, slug) ne sait pas faire. Ici il n'y a
            // que des insertions, et le `Dockerfile` de HEAD rejoue
            // `db:seed --class=TrimsSeeder --force` a chaque demarrage :
            // ce seeder EST le chemin de production. Sa garde `$exists` sur
            // (vehicle_model_id, name) rend le rejeu idempotent. Verifie en
            // relisant la ligne dans HEAD, pas en le supposant — elle n'est
            // pas dans le diff non commite du Dockerfile.
            //
            // ─── LA MOTRICITE RESTE NULLE SUR LE TACOMA, ET C'EST VOULU ─
            //
            // Ce n'est pas un oubli, et c'est la decision la plus
            // consequente du lot. Sur un pick-up Tacoma, la motricite est
            // une CONFIGURATION choisie a la commande, pas une propriete de
            // la finition : la meme SR5 existe en propulsion et en quatre
            // roues motrices. Aucune de mes sources ne donne la motricite
            // finition par finition — la fiche de specifications Cars.com du
            // millesime 2020 ne nomme « Rear Wheel Drive » que sur la seule
            // SR Access Cab, et laisse les autres sans mention.
            //
            // POSER UN DEFAUT FAUX N'EST PAS NEUTRE, et le code le montre :
            // `OwnedVehicle::getEffectiveDrivetrainIdAttribute` retombe sur
            // `trim->default_drivetrain_id` quand le client n'a rien saisi,
            // et `CompatibilityService::partsForCriteria` filtre alors
            // « drivetrain_id IS NULL OR = X » sur les compatibilites. Un
            // defaut faux CACHE une piece qui convient. Un defaut nul, lui,
            // ne filtre rien du tout : la condition est gardee par
            // `if ($drivetrainId !== null)`. Le nul est donc le choix large,
            // le faux le choix etroit.
            //   La colonne est `nullable` depuis la migration
            //   2026_01_01_000900, et la fixture `catalog_sync.json` porte
            //   deja des finitions a `default_drivetrain_id` nul : ce n'est
            //   pas un etat nouveau pour l'API.
            //   UNE SEULE EXCEPTION, parce qu'elle est sourcee au mot :
            //   la « TRD PreRunner » de la quatrieme generation, que
            //   Wikipedia donne « an off-road oriented two-wheel drive
            //   trim ». Propulsion, donc, et dit par la source.
            //
            // LE RESTE DES MOTRICITES EST POSE, parce qu'il ne fait pas de
            // doute : le Land Cruiser 300 n'a jamais existe qu'en quatre
            // roues motrices, et la Yaris qu'en traction. Les lignes
            // land-cruiser-j200 et yaris-xp210 de ce meme fichier portent
            // deja ces valeurs.
            //
            // ET CELA NE MASQUE RIEN AUJOURD'HUI, mesure : sur les 94
            // compatibilites du catalogue, QUATORZE portent une motricite et
            // les quatorze sont des Lexus. Les dix lignes des modeles vises
            // ici — fortuner-an50, hilux-an10, land-cruiser-j100,
            // land-cruiser-prado-j120, tacoma-iii, yaris-xp130 — ont toutes
            // `drivetrain_id` NULL et `trim_id` NULL. Le constat vaut pour
            // aujourd'hui et pas pour demain, et c'est justement pourquoi un
            // defaut faux est refuse.

            // ── TOYOTA TACOMA III (2016-2023, Amerique du Nord) ──────────
            // Sources, consultees le 21/10/2026 :
            //   - en.wikipedia.org/wiki/Toyota_Tacoma, troisieme generation,
            //     qui enumere au mot : « is available in 6 trim levels: base
            //     SR, mid-level SR5, TRD Off-Road, TRD Sport, Limited, and
            //     range-topping TRD Pro », et donne le 2,7 2TR-FE a 159 ch
            //     et le 3,5 2GR-FKS a 278 ch.
            //   - cars.com/research/toyota-tacoma-2020/, qui ecrit « available
            //     in SR, SR5, TRD Sport, TRD Off Road, Limited and TRD Pro
            //     trims », et dont la fiche de specifications donne le detail
            //     qui manque a Wikipedia : SR et SR5 en quatre cylindres OU
            //     V6, « V6 only » sur TRD Sport, TRD Off Road, Limited et
            //     TRD Pro, et 159 ch a 5200 tr/min pour le 2,7.
            // LES SIX NOMS SONT LES MEMES CHEZ LES DEUX. Rien d'ecarte ici.
            //
            // LE CATALOGUE DE FILTRES DU DEPOT FAIT LA TROISIEME SOURCE sur
            // le V6, et il nomme la generation : sa ligne tacoma-iii dit
            // « Tacoma 3.5 2GR-FKS 2016-2023 RWD / 4WD », statut
            // « Confirme ». Aucune contradiction : c'est le meme moteur, le
            // meme code, les memes annees. Il ne porte RIEN pour le 2,7
            // 2TR-FE — ce n'est pas un desaccord, c'est un trou du catalogue
            // de filtres, et il vaut d'etre su.
            //
            // LES DEUX SOURCES SE CONTREDISENT SUR UN SEUL POINT, L'ORDRE :
            // Wikipedia classe TRD Off-Road AVANT TRD Sport, Cars.com
            // l'inverse. Cars.com est suivi, et pour une raison verifiable
            // plutot que par preference : la TRD Sport est moins chere que
            // la TRD Off-Road au tarif de la generation suivante, seule
            // echelle de prix que j'aie pu lire. Le rang n'atteint que
            // l'ordre d'affichage.
            //
            // LA PUISSANCE DU V6 N'EST PAS DANS LA FICHE CARS.COM, qui donne
            // le quatre cylindres seul : les 278 ch viennent de Wikipedia, et
            // se recoupent avec les 278 ch annonces par la presse
            // professionnelle du millesime 2020. Colonne non stockee.
            ['toyota', 'tacoma-iii', 'SR',            'SR',    1, $petrol, null, 159, 2694, 4, 'automatic', '2,7 4 cylindres 2TR-FE de 159 ch ou 3,5 V6 2GR-FKS de 278 ch — entrée de gamme nord-américaine'],
            ['toyota', 'tacoma-iii', 'SR5',           'SR5',   2, $petrol, null, 159, 2694, 4, 'automatic', '2,7 4 cylindres 2TR-FE de 159 ch ou 3,5 V6 2GR-FKS de 278 ch — gamme nord-américaine'],
            ['toyota', 'tacoma-iii', 'TRD Sport',     'TRDS',  3, $petrol, null, 278, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 278 ch — V6 seulement, jamais le 2,7 ; finition routière nord-américaine'],
            ['toyota', 'tacoma-iii', 'TRD Off-Road',  'TRDO',  4, $petrol, null, 278, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 278 ch — V6 seulement ; finition tout-terrain nord-américaine'],
            ['toyota', 'tacoma-iii', 'Limited',       'LTD',   5, $petrol, null, 278, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 278 ch — V6 seulement ; finition haute nord-américaine'],
            ['toyota', 'tacoma-iii', 'TRD Pro',       'TRDP',  6, $petrol, null, 278, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 278 ch — V6 seulement ; haut de gamme tout-terrain nord-américain, apparu au millésime 2017'],

            // ── TOYOTA TACOMA IV (2023→, Amerique du Nord) ───────────────
            // Sources, consultees le 21/10/2026 :
            //   - en.wikipedia.org/wiki/Toyota_Tacoma, quatrieme generation :
            //     huit finitions, dont six reprises de la precedente — SR,
            //     SR5, Limited, TRD Sport, TRD Off-Road, TRD Pro — et deux
            //     nouvelles, la Trailhunter et le retour de la TRD PreRunner,
            //     decrite « an off-road oriented two-wheel drive trim ». Le
            //     2,4 turbo T24A-FTS y est donne « 228-278 hp depending on
            //     trim and transmission », l'hybride i-Force Max a 326 ch
            //     cumules.
            //   - cars.com/research/toyota-tacoma-2024/, dont la foire aux
            //     questions enumere Limited, SR, SR5, TRD Off Road, TRD
            //     PreRunner et TRD Sport, et dont la section moteur nomme les
            //     deux dernieres en disant que l'hybride de 326 ch « is
            //     standard on the Trailhunter and TRD Pro ». Les huit noms y
            //     sont donc tous, et le 278 ch du turbo y est confirme.
            //
            // LES 228 CH NE SONT PAS ATTRIBUES A UNE FINITION PRECISE, et je
            // ne le fais donc pas : Wikipedia donne une FOURCHETTE, 228 a
            // 278 ch « selon la finition et la boite », sans dire laquelle
            // recoit laquelle. La description porte la fourchette telle
            // quelle plutot qu'un chiffre invente par finition. La colonne de
            // puissance, qui n'atteint pas la base, porte 278 sauf sur la SR
            // ou elle porte 228 pour que le rang reste comparable — c'est la
            // meme convention que la ligne hybride de la Camry XV70 plus
            // haut, qui porte le cumul en colonne et le thermique en
            // description.
            //
            // L'HYBRIDE EST DE SERIE SUR TRD PRO ET TRAILHUNTER, dit par les
            // deux sources, et c'est pourquoi ces deux lignes seules portent
            // le type `hybrid`. Cars.com montre aussi l'hybride en OPTION
            // sur TRD Sport et TRD Off-Road : l'option est nommee dans leur
            // description, elle ne change pas leur type par defaut.
            //
            // LE CATALOGUE DE FILTRES NE CONNAIT PAS CETTE GENERATION : rien
            // pour tacoma-iv, ni pour le T24A-FTS. Ni confirmation ni
            // contradiction, et c'est a signaler plutot qu'a taire.
            //
            // L'ORDRE DES RANGS SUIT DES FAITS SOURCES, pas un tarif que je
            // n'ai pas lu : SR puis SR5 sont donnees « base » et courante par
            // Wikipedia ; la TRD PreRunner est la tout-terrain a deux roues
            // motrices, donc sous les tout-terrain a quatre ; la Limited est
            // la finition de confort ; TRD Pro et Trailhunter ferment la
            // marche, ce sont les deux seules a hybride de serie.
            ['toyota', 'tacoma-iv', 'SR',             'SR',    1, $petrol, null, 228, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, de 228 à 278 ch selon la finition et la boîte — entrée de gamme nord-américaine'],
            ['toyota', 'tacoma-iv', 'SR5',            'SR5',   2, $petrol, null, 278, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, de 228 à 278 ch selon la finition et la boîte — gamme nord-américaine'],
            ['toyota', 'tacoma-iv', 'TRD PreRunner',  'TRDPR', 3, $petrol, $rwd, 278, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS — finition tout-terrain nord-américaine à deux roues motrices, propulsion seule, carrosserie XtraCab'],
            ['toyota', 'tacoma-iv', 'TRD Sport',      'TRDS',  4, $petrol, null, 278, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, de 228 à 278 ch — gamme nord-américaine, hybride i-Force Max de 326 ch en option'],
            ['toyota', 'tacoma-iv', 'TRD Off-Road',   'TRDO',  5, $petrol, null, 278, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, de 228 à 278 ch — gamme nord-américaine, hybride i-Force Max de 326 ch en option'],
            ['toyota', 'tacoma-iv', 'Limited',        'LTD',   6, $petrol, null, 278, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, de 228 à 278 ch — finition de confort nord-américaine'],
            ['toyota', 'tacoma-iv', 'TRD Pro',        'TRDP',  7, $hybrid, null, 326, 2393, 4, 'automatic', '2,4 4 cylindres turbo hybride i-Force Max, 326 ch cumulés de série — haut de gamme tout-terrain nord-américain'],
            ['toyota', 'tacoma-iv', 'Trailhunter',    'TRH',   8, $hybrid, null, 326, 2393, 4, 'automatic', '2,4 4 cylindres turbo hybride i-Force Max, 326 ch cumulés de série — gamme nord-américaine, préparée pour le voyage tout-terrain'],

            // ── TOYOTA LAND CRUISER J300 (2021→, Australie) ─────────────
            // Sources, consultees le 21/10/2026 :
            //   - en.wikipedia.org/wiki/Toyota_Land_Cruiser, section J300,
            //     qui donne pour l'Australie « six grades: GX, GXL, VX,
            //     Sahara, GR Sport and Sahara ZX », et le F33A-FTV 3,3 V6
            //     diesel bi-turbo a 227 kW (304 ch).
            //   - 4x4australia.com.au, « 2022 Toyota LandCruiser 300 pricing
            //     and features », qui enumere les SIX MEMES noms avec leur
            //     tarif de lancement — GX 89 990, GXL 101 790, VX 113 990,
            //     Sahara 131 190, GR Sport 137 790, Sahara ZX 138 790 — et
            //     precise que les six partagent le meme « 3.3L twin-turbo V6
            //     diesel ».
            // LES SIX NOMS ET LEUR ORDRE SONT LES MEMES CHEZ LES DEUX, et le
            // rang suit l'echelle de prix, qui est lue et non supposee.
            //
            // POURQUOI L'AUSTRALIE ET PAS LE GOLFE. Wikipedia donne aussi six
            // noms pour le Moyen-Orient — GX, EX-R, GX-R, VX, VX-R et GR
            // Sport — et cinq pour le Japon. Je ne les pose pas : UNE SEULE
            // SOURCE chacun. C'est la meme regle qui a fait refuser les noms
            // du Golfe au lot precedent, et la liste de candidats du
            // proprietaire proposait justement « GX-R, VX-R, ZX » pour le
            // Golfe. Ecartes, donc, et le marche australien est nomme dans
            // chaque description pour qu'aucun « GX » ne soit pris pour
            // l'autre.
            //
            // L'AUSTRALIE EST A CONDUITE A DROITE, et le lot precedent s'en
            // est servi pour ecarter les noms britanniques de l'Auris. La
            // difference est reelle et je ne la cache pas : ici le marche est
            // NOMME dans la description, donc un vendeur voit a quoi il a
            // affaire, alors qu'un « Icon » d'Auris ne disait rien de son
            // pays. C'est un arbitrage de catalogue, et il vous revient de le
            // defaire si le parc ne vient jamais d'Australie.
            //
            // LE CATALOGUE DE FILTRES NE CONNAIT PAS LE J300 : mesure, zero
            // ligne pour land-cruiser-j300. Aucune confirmation interne du
            // F33A-FTV, et aucune contradiction non plus.
            //
            // LA GAMME AUSTRALIENNE EST ENTIEREMENT DIESEL, dit par les deux
            // sources. Le V35A-FTS essence de 409 ch existe, mais au Japon et
            // au Moyen-Orient, donc sur des noms que je n'ai pas poses.
            ['toyota', 'land-cruiser-j300', 'GX',        'GX',   1, $diesel, $dwa, 304, 3346, 6, 'automatic', '3,3 V6 diesel bi-turbo F33A-FTV, 304 ch, 4 roues motrices — entrée de gamme australienne'],
            ['toyota', 'land-cruiser-j300', 'GXL',       'GXL',  2, $diesel, $dwa, 304, 3346, 6, 'automatic', '3,3 V6 diesel bi-turbo F33A-FTV, 304 ch, 4 roues motrices — gamme australienne'],
            ['toyota', 'land-cruiser-j300', 'VX',        'VX',   3, $diesel, $dwa, 304, 3346, 6, 'automatic', '3,3 V6 diesel bi-turbo F33A-FTV, 304 ch, 4 roues motrices — gamme australienne'],
            ['toyota', 'land-cruiser-j300', 'Sahara',    'SAH',  4, $diesel, $dwa, 304, 3346, 6, 'automatic', '3,3 V6 diesel bi-turbo F33A-FTV, 304 ch, 4 roues motrices — finition haute australienne'],
            ['toyota', 'land-cruiser-j300', 'GR Sport',  'GRS',  5, $diesel, $dwa, 304, 3346, 6, 'automatic', '3,3 V6 diesel bi-turbo F33A-FTV, 304 ch, 4 roues motrices — haut de gamme tout-terrain australien'],
            ['toyota', 'land-cruiser-j300', 'Sahara ZX', 'SZX',  6, $diesel, $dwa, 304, 3346, 6, 'automatic', '3,3 V6 diesel bi-turbo F33A-FTV, 304 ch, 4 roues motrices — haut de gamme routier australien'],

            // ── TOYOTA YARIS XP130 (2011-2020, Amerique du Nord) ─────────
            // ATTENTION : LA LISTE DE CANDIDATS DU PROPRIETAIRE PORTE SUR UNE
            // AUTRE VOITURE. Elle propose « Yaris XP150 : J, E, G, S, TRD
            // Sportivo ; Xi, XS, Sport, Cross ». Ce sont les noms de la Yaris
            // et de la Vios d'ASIE. Or le lot precedent a corrige cette
            // ligne : le referentiel porte maintenant `yaris-xp130`, la
            // citadine a hayon d'Europe, du Japon et d'Amerique du Nord. Ces
            // candidats-la n'ont donc PLUS de ligne ou se poser, et les poser
            // sur la XP130 aurait reinstalle exactement le malentendu que le
            // lot precedent vient de defaire. ECARTES EN BLOC, et c'est le
            // desaccord le plus net de ce lot.
            //
            // Sources pour ce qui est pose, consultees le 21/10/2026 :
            //   - en.wikipedia.org/wiki/Toyota_Yaris_(XP130), qui ecrit pour
            //     l'Amerique du Nord « the base L (CE in Canada), mid-range
            //     LE and sport-tuned SE trim levels », et donne le 1NZ-FE
            //     1,5 pour le chassis NCP131.
            //   - cars.com/research/toyota-yaris-2015/, dont la foire aux
            //     questions dit « available in 3 trim levels » et dont les
            //     versions disponibles sont « Liftback Man L », « Auto L »,
            //     « Auto LE », « Man SE » et « Auto SE », avec un
            //     « 106-horsepower 1.5 liter four-cylinder ». La fiche du
            //     millesime 2017 du meme site redonne L, LE, SE et les memes
            //     106 ch.
            // LES TROIS NOMS SONT LES MEMES CHEZ LES DEUX.
            //
            // LE CATALOGUE DE FILTRES DU DEPOT CONFIRME LE MOTEUR ET LA
            // GENERATION, et il a ete corrige par le lot precedent : sa ligne
            // dit « Yaris 1.5 1NZ-FE », bornes 2011-2019, note « Borne basse
            // remontee a 2011, debut de la generation XP130 ». Meme code
            // moteur, meme cylindree, meme generation. Troisieme source, et
            // interne.
            //
            // LES 106 CH NE VIENNENT QUE DE CARS.COM, deux millesimes du meme
            // site : Wikipedia donne le CODE 1NZ-FE mais aucune puissance
            // pour cette generation. Je le dis plutot que de compter deux
            // sources ou il n'y en a qu'une. La colonne n'atteint pas la
            // base ; le code moteur, lui, est bien a deux sources.
            //
            // LA BOITE DEPARTAGE CES TROIS FINITIONS plus que la puissance,
            // qui est la meme : la LE n'existe qu'en automatique chez
            // Cars.com, la L et la SE existent dans les deux. Le rang suit
            // donc l'equipement, du plus modeste au plus sportif, et c'est
            // dit ici plutot que laisse deviner.
            //
            // LE MARCHE NORD-AMERICAIN EST NOMME DANS CHAQUE DESCRIPTION, et
            // il faut savoir ce que cela laisse de cote : le lot precedent a
            // etabli que ce parc vient du Japon, de Coree, d'Allemagne, de
            // France, des Pays-Bas et de Belgique. Un vendeur avec une Yaris
            // francaise ne trouvera donc pas sa finition — mais il n'en
            // trouvait AUCUNE avant ce lot, et la description l'empeche de
            // prendre une « SE » americaine pour la sienne.
            //
            // LES DEUX MARCHES QUI AURAIENT MIEUX VALU, ET POURQUOI ILS NE
            // SONT PAS POSES. Cette generation n'apparait pas dans le bloc des
            // ecartees plus bas, puisqu'elle est pourvue : sa part refusee est
            // donc dite ici.
            //   - LE JAPON est le marche le plus utile des deux, le parc en
            //     venant. Wikipedia donne la gamme au mot — « The Vitz was
            //     offered in F, U, Jewela and RS model grades » — mais une
            //     seule fois, et je n'ai pas trouve de seconde source. S'y
            //     ajoute que la voiture s'y nomme VITZ et non Yaris : poser
            //     « Jewela » sur une ligne « Yaris » demanderait de trancher
            //     si le referentiel suit le nom du marche ou celui du modele,
            //     ce qui n'est pas une question de finitions.
            //   - L'EUROPE n'a pas de gamme du tout chez Wikipedia pour cette
            //     generation : le seul nom qui y figure, « Trend », est dans
            //     une legende de photographie. Et le lot precedent a deja
            //     etabli que le nom de finition europeen change de pays en
            //     pays — c'est ce qui bloque l'Auris et l'Avensis.
            //   Une seconde source japonaise suffirait a poser quatre
            //   finitions de plus ici, et ce serait le meilleur rapport
            //   effort/utilite qui reste sur ce modele.
            ['toyota', 'yaris-xp130', 'L',  'L',  1, $petrol, $fwd, 106, 1497, 4, 'manual',    '1,5 4 cylindres 1NZ-FE, 106 ch, traction — entrée de gamme nord-américaine, « CE » au Canada'],
            ['toyota', 'yaris-xp130', 'LE', 'LE', 2, $petrol, $fwd, 106, 1497, 4, 'automatic', '1,5 4 cylindres 1NZ-FE, 106 ch, traction — gamme nord-américaine, automatique seulement'],
            ['toyota', 'yaris-xp130', 'SE', 'SE', 3, $petrol, $fwd, 106, 1497, 4, 'manual',    '1,5 4 cylindres 1NZ-FE, 106 ch, traction — finition sportive nord-américaine, même moteur que la LE'],

            // ══ LES NEUF GENERATIONS ECARTEES, UNE PAR UNE ═══════════════
            //
            // Aucune n'est ecartee par manque d'envie, et quatre le sont sur
            // une CONTRADICTION ENTRE SOURCES que je nomme.
            //
            // ─── 1 et 2. TACOMA I (1995-2004) ET TACOMA II (2004-2015) ──
            // Ce sont les deux ecarts les plus instructifs du lot, parce que
            // les generations III et IV du MEME modele ont ete posees juste
            // au-dessus : la difference n'est pas la disponibilite des
            // sources, c'est que L'ECHELLE DE FINITIONS N'EXISTAIT PAS
            // ENCORE. Elle commence au millesime 2016.
            //   Avant elle, le Tacoma se vendait par CONFIGURATION, et mes
            //   sources ne s'accordent donc pas sur ce qu'est une finition :
            //   - cars.com/research/toyota-tacoma-2002/ annonce « 3 trim
            //     levels » et nomme PreRunner, S-Runner et une troisieme
            //     sans nom ; ses versions s'appellent « Reg Cab Manual (GS) »
            //     ou « XtraCab V6 Manual S-Runner (GS) » — de la carrosserie
            //     et de la boite, pas une gamme.
            //   - cars.com/research/toyota-tacoma-2015/ annonce « 3 trim
            //     levels » et ne nomme que PreRunner et TRD Pro, la troisieme
            //     restant sans nom — alors que Wikipedia en donne six pour la
            //     meme annee.
            //   - en.wikipedia.org/wiki/Toyota_Tacoma nomme bien SR, SR5, TRD
            //     Sport, TRD Off-Road, X-Runner et TRD Pro sur la deuxieme
            //     generation, mais place la SR « new in 2014 » et la TRD Pro
            //     en 2015 : deux noms sur les onze derniers mois d'une
            //     generation de onze ans.
            //   - et les recoupements concordent sur le point qui tranche :
            //     la SR5 de ces annees-la est un ENSEMBLE D'OPTIONS, pas une
            //     finition, tandis que PreRunner et X-Runner designent une
            //     motricite et un chassis.
            //   Poser « SR5 » sur tacoma-ii ferait donc passer un ensemble
            //   d'options pour une finition, et poser « PreRunner » ferait
            //   passer une motricite pour une gamme. Les deux generations
            //   restent vides, et la raison est dans la voiture, pas dans la
            //   recherche.
            //
            // ─── 3. LAND CRUISER PRADO J120 (2002-2009) ─────────────────
            // A UNE SOURCE PRES. Wikipedia donne une liste japonaise propre
            // et citable — « The Japanese market Prado consisted of 6 trim
            // levels known as RX, RZ, TX, TX Limited, TZ and TZ "G
            // Selection" » — qui est exactement la liste de candidats du
            // proprietaire. Il manque la seconde source, et les deux que j'ai
            // essayees ne valent pas :
            //   - drom.ru/catalog/toyota/land_cruiser_prado/ ne donne AUCUN
            //     nom de finition pour le J120, seulement des codes chassis.
            //     Mesure : RX, RZ, TX et TZ n'y figurent pas.
            //   - une page de guide d'importation que je ne cite pas comme
            //     source s'est disqualifiee : son contenu, tel que me l'a
            //     rendu l'outil de recuperation, CONTREDIT ce qu'un extrait
            //     de recherche du meme site affirmait au meme moment — l'un
            //     dit que les noms d'export GX et VX « ne s'appliquent pas »
            //     a cette voiture, l'autre que sa table de finitions les
            //     emploie. Elle date aussi le passage au 1GR-FE d'aout 2005
            //     quand Wikipedia le met en juillet 2004. Une source qui se
            //     contredit sur elle-meme ne compte pas pour une.
            //   La gamme australienne de la meme generation n'est pas mieux
            //   servie : Wikipedia ne nomme GXL, VX et Grande que dans des
            //   legendes de photographies, et ne mentionne pas la GX.
            //   CE QU'IL FAUDRAIT : un catalogue japonais d'epoque, ou une
            //   fiche Toyota Australie archivee. La liste de candidats est
            //   probablement juste — elle n'est pas prouvee.
            //
            // ─── 4. FORTUNER AN50 (2005-2015) ──────────────────────────
            // ECARTE SUR UNE CONTRADICTION, et elle porte precisement sur un
            // nom de la liste de candidats. Celle-ci propose « G, G Luxury,
            // V, TRD Sportivo » pour l'Asie.
            //   - en.wikipedia.org/wiki/Toyota_Fortuner, premiere generation,
            //     dit pour la Thailande « with two grade levels: the
            //     entry-level G, and the top-spec V », et DEUX seulement. Il
            //     range explicitement la TRD Sportivo dans la DEUXIEME
            //     generation, pas la premiere.
            //   - un miroir de Wikipedia donne au contraire « G and G Luxury
            //     for rear-wheel drive diesel and petrol, and V for 4x4 » —
            //     donc une « G Luxury » que l'article lui-meme ne porte pas.
            //   - les sources philippines nomment les finitions AVEC LE
            //     MOTEUR DEVANT — « 2.7 G gasoline, 2.5 G diesel, 3.0 V
            //     diesel » — ce qui est une autre convention de nommage, et
            //     la plus serieuse d'entre elles est un wiki de fans.
            //   Trois conventions, et la seule qui soit propre n'a qu'une
            //   source. Les noms du Golfe — Wikipedia donne « two grades sold
            //   in Saudi Arabia, called the "GX" and the "VX" » et une liste
            //   d'Emirats qu'il annonce a quatre variantes en en citant cinq
            //   — tombent sous la meme regle que le lot precedent.
            //   A NOTER POUR QUI REPRENDRA : le catalogue de filtres du depot
            //   donne a fortuner-an50 le 2KD-FTV 2,5 diesel et le 1GR-FE 4,0
            //   V6 essence. Le 1GR-FE 4,0 CORROBORE la « VX » saoudienne, que
            //   Wikipedia donne en 4,0 V6 — c'est la premiere fois qu'une
            //   source interne appuie un nom du Golfe. Une seconde source
            //   externe sur la gamme saoudienne suffirait a debloquer ce
            //   modele. Le catalogue ne porte en revanche AUCUNE ligne 2,7
            //   essence, la motorisation de la « G » thailandaise.
            //
            // ─── 5. HILUX AN10 (2004-2015) ─────────────────────────────
            // RIEN DE CITABLE, et ce n'est pas faute d'avoir cherche la
            // bonne page. L'article Wikipedia du Hilux ne contient, pour
            // toute sa septieme generation, AUCUNE phrase qui enumere une
            // gamme : les deux seuls noms qui apparaissent le font dans des
            // libelles d'essais de choc ANCAP — « Hilux 4x2 Workmate single
            // cab chassis (2005) » et « Hilux 4x4 SR dual cab (2005) ». La
            // SR5 de la liste de candidats n'y figure pas une fois pour cette
            // generation, et les noms sud-africains qu'elle propose — SRX,
            // Raider, Legend 35/40/45 — pas davantage.
            //   Un libelle d'essai de choc n'est pas une gamme : il nomme le
            //   vehicule teste, pas les finitions vendues. Deux noms tires de
            //   deux crash-tests ne font pas une echelle.
            //   Le catalogue de filtres du depot confirme les MOTEURS
            //   (1KD-FTV 3,0 diesel 2005-2015 et 1GR-FE 4,0 V6 2005-2013),
            //   et c'est tout : un code moteur ne nomme pas une finition.
            //
            // ─── 6. LAND CRUISER J100 (1998-2007) ──────────────────────
            // UN SEUL NOM SOURCE, et c'est le plus haut de la gamme.
            // Wikipedia ne nomme pour l'Australie que « the range-topping
            // GXV model », et rien d'autre : ni Standard, ni GXL, ni Sahara.
            // Pour le Japon il ne donne que le NOM DU MODELE — la J100 y
            // etait vendue « as the Toyota Cygnus » — et « VX Limited »
            // n'apparait que dans une legende de photographie. La liste de
            // candidats en propose onze ; les sources en nomment un.
            //   Poser la seule GXV donnerait un catalogue ou la finition la
            //   plus chere est la seule proposee — pire qu'une case vide pour
            //   un vendeur qui a la version de base.
            //   Le catalogue de filtres donne le 2UZ-FE 4,7 V8 1998-2007, ce
            //   qui concorde avec la J100 a V8 mais ne nomme aucune finition.
            //
            // ─── 7. RUSH F800 (2017→) ──────────────────────────────────
            // LES NOMS CHANGENT DE MARCHE EN MARCHE, et la liste de
            // candidats les melange en une seule ligne « G, S, TRD Sportivo,
            // GR Sport ». Wikipedia, qui traite ce modele sous le Daihatsu
            // Terios, les separe :
            //   Indonesie « The Rush came with G and TRD Sportivo grades »,
            //   la TRD Sportivo devenant GR Sport en aout 2021 ; Philippines
            //   E et G, la G remplacee par GR Sport en mai 2022, puis la E
            //   retiree en janvier 2024 ; Malaisie « two grade levels were
            //   offered: 1.5 G and 1.5 S ».
            //   La « S » n'existe donc qu'en Malaisie, et Wikipedia la rattache
            //   par ailleurs au Rush F700, la generation PRECEDENTE. Poser les
            //   quatre noms cote a cote ferait une gamme qu'aucun pays n'a
            //   jamais vendue.
            //   Il manque de toute facon la seconde source, et le catalogue
            //   de filtres du depot ne connait pas rush-f800 : mesure, zero
            //   ligne.
            //
            // ─── 8 et 9. AURIS E180 ET AVENSIS T270 ────────────────────
            // DEJA ECARTEES PAR LE LOT PRECEDENT, et je ne renverse pas sa
            // decision parce que je n'ai pas trouve de quoi la renverser :
            // il faudrait une source par PAYS europeen, le nom de finition y
            // changeant d'un marche a l'autre. Les listes de candidats le
            // montrent elles-memes, en donnant deux jeux pour l'Auris
            // (Royaume-Uni et France) et trois pour l'Avensis.
            //   Je n'ai pas depense de recherche sur ces deux-la : la raison
            //   ecrite plus haut dans ce fichier tient toujours, et aucune
            //   de mes sources utilisables ne couvre les gammes francaise,
            //   allemande, neerlandaise ou belge generation par generation.
            //   C'est un travail de catalogue constructeur archive, pas de
            //   recoupement encyclopedique.
            //
            // ─── CE QUE JE N'AI PAS PU OUVRIR, ET QUI NE SERT DONC A RIEN ─
            // kbb.com, toyota.com.au, autodeal.com et pressroom.toyota.com
            // rendent HTTP 403 ou 404 a l'outil de recuperation ;
            // caranddriver.com est refuse en amont. Rien n'en a ete tire et
            // aucune n'est citee comme source. La liste de candidats du
            // proprietaire citait des pages Toyota sans lien ouvrable : je
            // n'en ai ouvert aucune, et c'est pourquoi les gammes du Golfe
            // restent refusees pour la deuxieme fois.

            // ── NISSAN PATROL Y61 ────────────────────────────────────────
            ['nissan', 'patrol-y61', 'DX',         'DX',   1, $diesel, $dwa, 130, 2953, 6, 'manual'],
            ['nissan', 'patrol-y61', 'SE',         'SE',   2, $petrol, $dwa, 170, 2997, 6, 'automatic'],
            ['nissan', 'patrol-y61', 'GRX',        'GRX',  3, $petrol, $dwa, 202, 3956, 6, 'automatic'],

            // ── NISSAN NAVARA D23 ────────────────────────────────────────
            ['nissan', 'navara-d23', 'S',          'S',    1, $diesel, $rwd, 160, 2298, 4, 'manual'],
            ['nissan', 'navara-d23', 'SL',         'SL',   2, $diesel, $dwa, 190, 2298, 4, 'manual'],
            ['nissan', 'navara-d23', 'SV',         'SV',   3, $diesel, $dwa, 190, 2298, 4, 'automatic'],
            ['nissan', 'navara-d23', 'PRO-4X',     'PRO',  4, $diesel, $dwa, 190, 2298, 4, 'automatic'],

            // ── NISSAN X-TRAIL T32 ───────────────────────────────────────
            ['nissan', 'x-trail-t32', 'Visia',     'VIS',  1, $petrol, $fwd, 132, 1597, 4, 'manual'],
            ['nissan', 'x-trail-t32', 'Acenta',    'ACE',  2, $petrol, $fwd, 163, 1997, 4, 'cvt'],
            ['nissan', 'x-trail-t32', 'Tekna',     'TEK',  3, $diesel, $awd, 177, 1597, 4, 'cvt'],

            // ── HYUNDAI TUCSON TL ────────────────────────────────────────
            ['hyundai', 'tucson-tl', 'Active',     'ACT',  1, $petrol, $fwd, 132, 1591, 4, 'manual'],
            ['hyundai', 'tucson-tl', 'Active X',   'AXP',  2, $diesel, $fwd, 115, 1685, 4, 'manual'],
            ['hyundai', 'tucson-tl', 'Elite',      'ELI',  3, $petrol, $awd, 177, 1999, 4, 'automatic'],
            ['hyundai', 'tucson-tl', 'Highlander', 'HLD',  4, $diesel, $awd, 177, 1685, 4, 'automatic'],

            // ── HYUNDAI SANTA FE TM ──────────────────────────────────────
            ['hyundai', 'santa-fe-tm', 'Essential', 'ESS', 1, $petrol, $fwd, 188, 2359, 4, 'automatic'],
            ['hyundai', 'santa-fe-tm', 'Comfort',   'COM', 2, $diesel, $awd, 202, 2199, 4, 'automatic'],
            ['hyundai', 'santa-fe-tm', 'Premium',   'PRE', 3, $diesel, $awd, 202, 2199, 4, 'automatic'],

            // ── KIA SPORTAGE QL ──────────────────────────────────────────
            ['kia', 'sportage-ql', 'LX',           'LX',   1, $petrol, $fwd, 132, 1591, 4, 'manual'],
            ['kia', 'sportage-ql', 'EX',           'EX',   2, $petrol, $fwd, 177, 1999, 4, 'automatic'],
            ['kia', 'sportage-ql', 'SX',           'SX',   3, $diesel, $awd, 182, 1685, 4, 'automatic'],
            ['kia', 'sportage-ql', 'GT Line',      'GTL',  4, $diesel, $awd, 182, 1685, 4, 'automatic'],

            // ── MITSUBISHI PAJERO V80 ────────────────────────────────────
            ['mitsubishi', 'pajero-v80', 'GLX',    'GLX',  1, $diesel, $dwa, 170, 3200, 4, 'manual'],
            ['mitsubishi', 'pajero-v80', 'GLS',    'GLS',  2, $diesel, $dwa, 200, 3200, 4, 'automatic'],
            ['mitsubishi', 'pajero-v80', 'Exceed', 'EXC',  3, $petrol, $dwa, 250, 3828, 6, 'automatic'],

            // ── MITSUBISHI L200 KL ───────────────────────────────────────
            ['mitsubishi', 'l200-kl', 'Invite',    'INV',  1, $diesel, $rwd, 150, 2268, 4, 'manual'],
            ['mitsubishi', 'l200-kl', 'GL',        'GL',   2, $diesel, $dwa, 150, 2268, 4, 'manual'],
            ['mitsubishi', 'l200-kl', 'GLS',       'GLS',  3, $diesel, $dwa, 150, 2268, 4, 'automatic'],
            ['mitsubishi', 'l200-kl', 'Athlete',   'ATH',  4, $diesel, $dwa, 181, 2268, 4, 'automatic'],

            // ── PEUGEOT 3008 ─────────────────────────────────────────────
            ['peugeot', '3008', 'Active',          'ACT',  1, $petrol, $fwd, 130, 1199, 3, 'manual'],
            ['peugeot', '3008', 'Allure',          'ALL',  2, $petrol, $fwd, 130, 1199, 3, 'automatic'],
            ['peugeot', '3008', 'GT Line',         'GTL',  3, $diesel, $fwd, 130, 1499, 4, 'automatic'],
            ['peugeot', '3008', 'GT',              'GT',   4, $petrol, $awd, 225, 1598, 4, 'automatic'],

            // ── RENAULT DUSTER ───────────────────────────────────────────
            ['renault', 'duster', 'Life',          'LIF',  1, $petrol, $fwd, 115, 1598, 4, 'manual'],
            ['renault', 'duster', 'Zen',           'ZEN',  2, $petrol, $fwd, 115, 1598, 4, 'manual'],
            ['renault', 'duster', 'Intens',        'INT',  3, $diesel, $fwd, 115, 1461, 4, 'manual'],
            ['renault', 'duster', 'Prestige 4x4',  'PRE',  4, $diesel, $dwa, 115, 1461, 4, 'manual'],

            // ── VOLKSWAGEN TIGUAN ────────────────────────────────────────
            ['volkswagen', 'tiguan-ad', 'Trendline', 'TRE', 1, $petrol, $fwd, 125, 1395, 4, 'manual'],
            ['volkswagen', 'tiguan-ad', 'Comfortline','COM', 2, $petrol, $fwd, 150, 1395, 4, 'automatic'],
            ['volkswagen', 'tiguan-ad', 'Highline',  'HIG', 3, $diesel, $awd, 150, 1968, 4, 'automatic'],
            ['volkswagen', 'tiguan-ad', 'R-Line',    'RLI', 4, $petrol, $awd, 220, 1984, 4, 'automatic'],

            // ── FORD RANGER T6 ───────────────────────────────────────────
            ['ford', 'ranger-t6', 'XL',            'XL',   1, $diesel, $rwd, 150, 2198, 4, 'manual'],
            ['ford', 'ranger-t6', 'XLT',           'XLT',  2, $diesel, $dwa, 170, 3198, 4, 'manual'],
            ['ford', 'ranger-t6', 'Wildtrak',      'WDT',  3, $diesel, $dwa, 213, 3198, 4, 'automatic'],
            ['ford', 'ranger-t6', 'Raptor',        'RAP',  4, $petrol, $dwa, 213, 2689, 6, 'automatic'],

            // ── ISUZU D-MAX ──────────────────────────────────────────────
            ['isuzu', 'd-max-tfr', 'LS',           'LS',   1, $diesel, $rwd, 130, 2499, 4, 'manual'],
            ['isuzu', 'd-max-tfr', 'LT',           'LT',   2, $diesel, $dwa, 163, 2999, 4, 'manual'],
            ['isuzu', 'd-max-tfr', 'LS Premium',   'LSP',  3, $diesel, $dwa, 190, 2999, 4, 'automatic'],
            ['isuzu', 'd-max-tfr', 'V-Cross 4x4',  'VCR',  4, $diesel, $dwa, 163, 2999, 4, 'automatic'],

            // ── JEEP WRANGLER JL ─────────────────────────────────────────
            ['jeep', 'wrangler-jl', 'Sport',       'SPT',  1, $petrol, $dwa, 285, 3604, 6, 'manual'],
            ['jeep', 'wrangler-jl', 'Sahara',      'SAH',  2, $petrol, $dwa, 285, 3604, 6, 'automatic'],
            ['jeep', 'wrangler-jl', 'Rubicon',     'RUB',  3, $petrol, $dwa, 285, 3604, 6, 'automatic'],

            // ── LAND ROVER DISCOVERY L462 ────────────────────────────────
            ['land-rover', 'discovery-l462', 'S',  'S',    1, $diesel, $awd, 249, 2993, 6, 'automatic'],
            ['land-rover', 'discovery-l462', 'SE', 'SE',   2, $diesel, $awd, 302, 2993, 6, 'automatic'],
            ['land-rover', 'discovery-l462', 'HSE','HSE',  3, $petrol, $awd, 340, 2995, 6, 'automatic'],

            // ── MERCEDES CLASSE C W205 ───────────────────────────────────
            ['mercedes-benz', 'classe-c-w205', 'C 180',    'C180', 1, $petrol, $rwd, 156, 1595, 4, 'automatic'],
            ['mercedes-benz', 'classe-c-w205', 'C 200',    'C200', 2, $petrol, $rwd, 184, 1991, 4, 'automatic'],
            ['mercedes-benz', 'classe-c-w205', 'C 220d',   'C220D',3, $diesel, $rwd, 194, 1950, 4, 'automatic'],
            ['mercedes-benz', 'classe-c-w205', 'C 300 4M', 'C3004',4, $petrol, $awd, 258, 1991, 4, 'automatic'],

            // ── BMW SÉRIE 3 G20 ──────────────────────────────────────────
            ['bmw', 'serie-3-g20', '318i',         '318i', 1, $petrol, $rwd, 156, 1499, 4, 'automatic'],
            ['bmw', 'serie-3-g20', '320i',         '320i', 2, $petrol, $rwd, 184, 1998, 4, 'automatic'],
            ['bmw', 'serie-3-g20', '320d',         '320d', 3, $diesel, $rwd, 190, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-g20', '330i xDrive',  '330X', 4, $petrol, $awd, 258, 1998, 4, 'automatic'],

            // ══ BMW : LE RESTE DE LA GAMME ═══════════════════════════════
            //
            // La G20 au-dessus etait le SEUL modele BMW a porter des
            // finitions ; les huit autres n'en avaient aucune. D'ou la
            // remarque du proprietaire selon laquelle « la BMW 528i n'existe
            // pas » : la Serie 5 F10 etait bien au referentiel, aucune de ses
            // versions ne l'etait.
            //
            // CE QUE CE SEEDER ECRIT REELLEMENT : voir l'en-tete de la methode,
            // qui est la seule description a jour du contrat. En resume, la
            // puissance, la cylindree, le nombre de cylindres et la boite ne
            // sont PAS stockes — ce sont des commentaires structures — et
            // `$txMap` est du code mort, declare et jamais lu.
            //
            // CE PARAGRAPHE AFFIRMAIT LE CONTRAIRE, ET IL FAUT DIRE POURQUOI
            // PLUTOT QUE D'EFFACER. Ecrit avec le lot BMW, il soutenait que le
            // nom etait le seul porteur atteignant un vendeur, que
            // `trims.description` valait NULL partout, et que la remplir
            // « demanderait une valeur pour chacune des 160 lignes — un lot a
            // lui seul ». Le lot Lexus, deux heures plus tard, l'a faite en
            // TROIS retouches : une colonne facultative en douzieme position,
            // le champ dans l'`insert`, et les lignes anterieures laissees
            // telles quelles. Les quatre affirmations sont donc devenues
            // fausses, et le fichier s'est contredit entre sa ligne 36 et
            // celle-ci jusqu'a ce qu'une relecture le voie.
            //
            // La lecon n'est pas sur la colonne, elle est sur le cout : « un
            // lot a lui seul » etait une estimation posee sans l'avoir tentee,
            // et elle a servi de raison de ne pas faire. Un commentaire qui
            // chiffre un effort engage autant qu'un commentaire qui decrit un
            // comportement — et il se perime de la meme facon.
            //
            // Les dedoublements de nom ci-dessous RESTENT, et ce n'est pas par
            // inertie : « 528i (4 cyl. 2011-2016) » distingue deux voitures
            // dans la liste deroulante elle-meme, la ou une description ne se
            // lit qu'une fois la ligne ouverte. Les deux se completent.
            //
            // LE PIEGE DU NOM QUI NE SUIT PLUS LA CYLINDREE. Depuis 2010 une
            // 528i n'a pas de 2,8 l. Trois voitures differentes portent ce
            // nom dans ce fichier, et chacune a sa ligne :
            //   E60 2007-2010 : six cylindres 2996 N52B30, marche americain ;
            //   F10 2010-2011 : six cylindres 2996 N52B30 ;
            //   F10 2011-2016 : QUATRE cylindres 1997 N20B20 turbo, des 09/2011.
            // Les deux F10 rendent la meme puissance — 180 kW —, donc la
            // puissance ne les distingue pas ; seuls la cylindree et le
            // nombre de cylindres le font, et ni l'une ni l'autre ne va en
            // base. Le nom les porte.
            //
            // REGLE APPLIQUEE. Un nom dont le nombre de cylindres change en
            // cours de generation est dedouble, le nom portant la distinction
            // (F10 528i, et F10 525d qui subit la meme rupture six vers
            // quatre). Quand seuls la cylindree ou la mise au point changent
            // a nombre de cylindres constant, une seule ligne, portant la
            // version la plus probable a l'import ici, et le commentaire
            // nomme l'autre.
            //
            // SOURCES. Chaque code d'usine et chaque borne est recoupe sur au
            // moins deux sources — Wikipedia anglais et Wikipedia allemand
            // (redactions independantes), completes par les fiches moteur
            // N52/N20/B48 et, pour le basculement de la 528i, le communique
            // BMW de juillet 2011 relaye par BMWBlog. Les divergences
            // retenues sont nommees ligne par ligne.
            //
            // BOITE. 'automatic' partout, comme les quatre lignes G20
            // existantes : les boites manuelles existaient en Europe sur
            // presque toutes ces versions, mais les voitures qui arrivent
            // d'Amerique du Nord et du Golfe sont automatiques, et c'est de
            // la que vient le parc. Valeur non stockee de toute facon.

            // ── BMW SÉRIE 1 F20 ─────────────────────────────────────────
            // Millesimes d'avant le LCI de 2015 : N13B16 essence 1598 et
            // N47D20 diesel 1995, soit la tranche 2011-2015 qui s'importe
            // aujourd'hui. Apres 2015 BMW passe les 116i et 118i au trois
            // cylindres B38B15 1499 (109 et 136 ch) et les diesels au B47D20
            // (150 et 190 ch) : meme nom, cylindree et cylindres differents.
            // Propulsion de serie sur toute la F20, xDrive seulement en
            // option sur 120d et M135i.
            ['bmw', 'serie-1-f20', '116i',        '116i', 1, $petrol, $rwd, 136, 1598, 4, 'automatic'],
            ['bmw', 'serie-1-f20', '118d',        '118d', 2, $diesel, $rwd, 143, 1995, 4, 'automatic'],
            ['bmw', 'serie-1-f20', '118i',        '118i', 3, $petrol, $rwd, 170, 1598, 4, 'automatic'],
            ['bmw', 'serie-1-f20', '120d',        '120d', 4, $diesel, $rwd, 184, 1995, 4, 'automatic'],

            // ── BMW SÉRIE 3 F30 ─────────────────────────────────────────
            // 318i B38B15 trois cylindres 1499, posterieure au LCI : ce nom
            // n'a jamais designe autre chose sur la F30, la base d'avant 2015
            // etant la 316i. 320i N20B20 1997 puis B48B20 1998, 184 ch dans
            // les deux cas. 320d N47D20 puis B47D20, 1995 : 184 ch jusqu'en
            // 2015, 190 ensuite — la valeur posee est celle du B47, les
            // millesimes tardifs etant ceux qui s'importent. La 328i est la
            // version de volume nord-americaine, remplacee par la 330i au
            // LCI : deux noms, deux lignes, aucune ambiguite.
            ['bmw', 'serie-3-f30', '318i',        '318i', 1, $petrol, $rwd, 136, 1499, 3, 'automatic'],
            ['bmw', 'serie-3-f30', '320i',        '320i', 2, $petrol, $rwd, 184, 1997, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '320d',        '320d', 3, $diesel, $rwd, 190, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '328i',        '328i', 4, $petrol, $rwd, 245, 1997, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '330i',        '330i', 5, $petrol, $rwd, 252, 1998, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '330d',        '330d', 6, $diesel, $rwd, 258, 2993, 6, 'automatic'],

            // ── BMW SÉRIE 3 E90 ─────────────────────────────────────────
            // Versions posterieures au LCI de 09/2008, celles qui dominent le
            // parc importe. 318i et 320i : N43B20 1995, 143 et 170 ch (avant
            // 09/2007, N46B20 de meme cylindree, 129 et 150 ch). 320d N47D20
            // 1995 a 177 ch (M47D20TU2 163 ch avant 09/2007, 184 ch a partir
            // de 03/2010). 325i : N53B30 2996 a partir de 09/2007 contre
            // N52B25 2497 avant, a puissance IDENTIQUE de 218 ch — le nom ne
            // dit rien de la cylindree, la ligne porte celle du N53.
            // 328i N52B30 2996 : version nord-americaine, absente d'Europe,
            // et c'est la raison de sa presence. 330d N57D30OL 2993.
            // Les sources divergent sur la puissance de la 328i, 172 kW
            // partout mais 233 ch cote allemand et 230 hp cote americain :
            // meme moteur, deux normes de mesure, 233 retenu.
            ['bmw', 'serie-3-e90', '318i',        '318i', 1, $petrol, $rwd, 143, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-e90', '320i',        '320i', 2, $petrol, $rwd, 170, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-e90', '320d',        '320d', 3, $diesel, $rwd, 177, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-e90', '325i',        '325i', 4, $petrol, $rwd, 218, 2996, 6, 'automatic'],
            ['bmw', 'serie-3-e90', '328i',        '328i', 5, $petrol, $rwd, 233, 2996, 6, 'automatic'],
            ['bmw', 'serie-3-e90', '330d',        '330d', 6, $diesel, $rwd, 245, 2993, 6, 'automatic'],

            // ── BMW SÉRIE 5 G30 ─────────────────────────────────────────
            // 520i B48B20 1998 a 184 ch. Un autre 520i a existe, B48B16 de
            // 1597 et 170 ch, mais pour la Turquie et la Tunisie : hors des
            // circuits d'importation d'ici, donc non pose. 530d B57D30 2993 a
            // 265 ch jusqu'en 2020, 286 ensuite ; 265 retenu, les G30 de
            // 2017-2020 etant celles qui arrivent. 540i xDrive B58B30 2998 :
            // seule integrale de la serie, les autres sont a propulsion.
            ['bmw', 'serie-5-g30', '520i',        '520i', 1, $petrol, $rwd, 184, 1998, 4, 'automatic'],
            ['bmw', 'serie-5-g30', '520d',        '520d', 2, $diesel, $rwd, 190, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-g30', '530i',        '530i', 3, $petrol, $rwd, 252, 1998, 4, 'automatic'],
            ['bmw', 'serie-5-g30', '530d',        '530d', 4, $diesel, $rwd, 265, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-g30', '540i xDrive', '540X', 5, $petrol, $awd, 340, 2998, 6, 'automatic'],

            // ── BMW SÉRIE 5 F10 ─────────────────────────────────────────
            // La generation du probleme signale. DEUX noms y sont dedoubles,
            // parce que le nombre de cylindres change sous un nom inchange et
            // que le millesime seul permet de trancher :
            //
            //   528i — six 2996 N52B30 jusqu'en 09/2011, puis QUATRE 1997
            //   N20B20 turbo. Les deux rendent 180 kW, soit 245 ch : la
            //   puissance ne distingue rien. Le six retenu est le N52B30 des
            //   marches nord-americain et du Golfe, d'ou vient le parc ; en
            //   Europe la 528i six cylindres etait un N53B30 de meme
            //   cylindree a 258 ch, injection directe exigeant un carburant
            //   desoufre, raison meme pour laquelle l'Amerique et le Golfe
            //   ont recu le N52.
            //
            //   525d — six 2993 N57D30 a 204 ch jusqu'en 09/2011, puis
            //   QUATRE 1995 N47D20 a 218 ch. Meme rupture, et le diesel
            //   525d est explicitement attendu sur le parc importe d'Europe.
            //   Les 218 ch du quatre cylindres ne sont attestes que par la
            //   source anglaise ; la source allemande confirme le code et la
            //   cylindree sans donner la puissance.
            //
            // 520d N47D20 1995 a 184 ch jusqu'en 2014, B47D20 190 ch ensuite ;
            // 184 retenu, la tranche 2010-2014 etant la plus longue et la
            // plus ancienne, donc la plus importee. 530d N57D30O1 2993 a
            // 258 ch a partir de 09/2011 (245 ch avant). 535i N55B30 2979,
            // seul six cylindres essence turbo de la gamme posee.
            ['bmw', 'serie-5-f10', '520i',        '520i',  1, $petrol, $rwd, 184, 1997, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '520d',        '520d',  2, $diesel, $rwd, 184, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '525d (6 cyl. 2010-2011)', '525d6', 3, $diesel, $rwd, 204, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-f10', '525d (4 cyl. 2011-2016)', '525d4', 4, $diesel, $rwd, 218, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '528i (6 cyl. 2010-2011)', '528i6', 5, $petrol, $rwd, 245, 2996, 6, 'automatic'],
            ['bmw', 'serie-5-f10', '528i (4 cyl. 2011-2016)', '528i4', 6, $petrol, $rwd, 245, 1997, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '530d',        '530d',  7, $diesel, $rwd, 258, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-f10', '535i',        '535i',  8, $petrol, $rwd, 306, 2979, 6, 'automatic'],

            // ── BMW SÉRIE 5 E60 ─────────────────────────────────────────
            // Versions posterieures au LCI de 03/2007. La 528i de cette
            // generation est le troisieme porteur du nom dans ce fichier :
            // N52B30 2996, six cylindres, 234 ch, vendue de 2007 a 2010 sur
            // le SEUL marche nord-americain — elle est absente des sources
            // allemandes pour cette raison, pas par contradiction. Les
            // sources divergent sur sa puissance, 234 ch en norme europeenne
            // contre 230 hp en norme americaine : meme moteur, 234 retenu.
            // 523i N53B25 2497 (N52B25 177 ch avant 2007). 525i N53B30 2996 a
            // partir de 2007 contre N52B25 2497 avant, a 218 ch dans les deux
            // cas : encore un nom muet sur la cylindree. 520d N47D20 1995
            // (M47 163 ch avant 2007). 530d et 535d sont des M57D30, famille
            // anterieure au N57 de la F10.
            ['bmw', 'serie-5-e60', '520d',        '520d', 1, $diesel, $rwd, 177, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-e60', '523i',        '523i', 2, $petrol, $rwd, 190, 2497, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '525i',        '525i', 3, $petrol, $rwd, 218, 2996, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '528i',        '528i', 4, $petrol, $rwd, 234, 2996, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '530d',        '530d', 5, $diesel, $rwd, 235, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '530i',        '530i', 6, $petrol, $rwd, 272, 2996, 6, 'automatic'],

            // ── BMW X1 F48 ──────────────────────────────────────────────
            // ATTENTION A LA MOTRICITE, qui elle va en base. La F48 est batie
            // sur la plateforme transversale UKL2 : « sDrive » y designe la
            // TRACTION AVANT, alors que sur tout le reste de la gamme BMW —
            // et sur l'X1 E84 qui la precede — « sDrive » designe la
            // propulsion. Deux voitures nommees « sDrive18i » n'ont donc pas
            // la meme motricite selon la generation.
            // sDrive18i B38A15 1499 trois cylindres, 136 ch au lancement ; la
            // source anglaise donne 141 hp, soit 105 kW contre 100 au
            // catalogue allemand, 136 retenu. xDrive25i B48B20 1998, vendue
            // xDrive28i aux Etats-Unis : meme voiture, deux appellations.
            ['bmw', 'x1-f48', 'sDrive18i',        '18i',  1, $petrol, $fwd, 136, 1499, 3, 'automatic'],
            ['bmw', 'x1-f48', 'sDrive18d',        '18d',  2, $diesel, $fwd, 150, 1995, 4, 'automatic'],
            ['bmw', 'x1-f48', 'xDrive20d',        '20dX', 3, $diesel, $awd, 190, 1995, 4, 'automatic'],
            ['bmw', 'x1-f48', 'xDrive25i',        '25iX', 4, $petrol, $awd, 231, 1998, 4, 'automatic'],

            // ── BMW X1 E84 ──────────────────────────────────────────────
            // Generation a PROPULSION : ici « sDrive » veut bien dire
            // propulsion, contrairement a la F48 ci-dessus. Les quatre noms
            // sont confirmes par la fiche de presse BMW du modele, qui liste
            // aussi le xDrive18d absent de la source anglaise.
            // sDrive18i N46B20 1995 atmospherique, 150 ch — la source
            // anglaise donne 148 hp pour les memes 110 kW.
            // xDrive28i N20B20 1997 turbo a partir de 2011 ; avant, le haut
            // de gamme etait un xDrive25i N52B30 2996 six cylindres a 215 ch,
            // non pose faute d'un volume d'importation credible.
            ['bmw', 'x1-e84', 'sDrive18d',        '18d',  1, $diesel, $rwd, 143, 1995, 4, 'automatic'],
            ['bmw', 'x1-e84', 'sDrive18i',        '18i',  2, $petrol, $rwd, 150, 1995, 4, 'automatic'],
            ['bmw', 'x1-e84', 'xDrive20d',        '20dX', 3, $diesel, $awd, 184, 1995, 4, 'automatic'],
            ['bmw', 'x1-e84', 'xDrive28i',        '28iX', 4, $petrol, $awd, 245, 1997, 4, 'automatic'],

            // ── BMW X3 G01 ──────────────────────────────────────────────
            // La xDrive30i est un QUATRE cylindres B48B20 de 1998, pas un six
            // : la source allemande la donne a six cylindres et 2998, ce qui
            // contredit la source anglaise, la fiche du moteur B48 et la
            // ligne 330i xDrive de la G20 ci-dessus, qui porte ce meme B48 a
            // 1998. La version allemande est ecartee comme erreur de colonne.
            // xDrive30d B57D30 2993, seul six cylindres pose ici.
            ['bmw', 'x3-g01', 'xDrive20i',        '20iX', 1, $petrol, $awd, 184, 1998, 4, 'automatic'],
            ['bmw', 'x3-g01', 'xDrive20d',        '20dX', 2, $diesel, $awd, 190, 1995, 4, 'automatic'],
            ['bmw', 'x3-g01', 'xDrive30i',        '30iX', 3, $petrol, $awd, 252, 1998, 4, 'automatic'],
            ['bmw', 'x3-g01', 'xDrive30d',        '30dX', 4, $diesel, $awd, 265, 2993, 6, 'automatic'],

            // ── BMW X3 F25 ──────────────────────────────────────────────
            // xDrive20i N20B20 1997. xDrive20d N47D20 1995 a 184 ch jusqu'en
            // 04/2014, B47D20 190 ch ensuite ; 184 retenu, tranche la plus
            // longue. xDrive30d N57D30 2993. xDrive35i N55B30 2979.
            // La xDrive28i est volontairement ABSENTE : la source anglaise la
            // donne en N20 quatre cylindres 1997 a partir de 2012, la source
            // allemande en N52B30 six cylindres 2996 sur la meme periode, a
            // puissance identique de 180 kW. C'est la rupture six-quatre de
            // la 528i, mais sans qu'aucune des deux sources ne permette de
            // dater la bascule. Poser la ligne obligerait a inventer la date.
            ['bmw', 'x3-f25', 'xDrive20d',        '20dX', 1, $diesel, $awd, 184, 1995, 4, 'automatic'],
            ['bmw', 'x3-f25', 'xDrive20i',        '20iX', 2, $petrol, $awd, 184, 1997, 4, 'automatic'],
            ['bmw', 'x3-f25', 'xDrive30d',        '30dX', 3, $diesel, $awd, 258, 2993, 6, 'automatic'],
            ['bmw', 'x3-f25', 'xDrive35i',        '35iX', 4, $petrol, $awd, 306, 2979, 6, 'automatic'],

            // ── BMW X5 G05 ──────────────────────────────────────────────
            // Trois lignes seulement : la G05 est encore recente pour un parc
            // d'occasion, et son haut de gamme est hors de portee ici.
            // xDrive25d B47B20 1995 quatre cylindres. xDrive30d B57D30 2993 a
            // 265 ch jusqu'en 2020, 286 ensuite ; 265 retenu. xDrive40i
            // B58B30 2998 a 333 ch en norme europeenne, 335 hp en norme
            // americaine sur la sDrive40i a propulsion vendue la seule
            // Amerique du Nord : meme moteur, 333 retenu.
            ['bmw', 'x5-g05', 'xDrive25d',        '25dX', 1, $diesel, $awd, 231, 1995, 4, 'automatic'],
            ['bmw', 'x5-g05', 'xDrive30d',        '30dX', 2, $diesel, $awd, 265, 2993, 6, 'automatic'],
            ['bmw', 'x5-g05', 'xDrive40i',        '40iX', 3, $petrol, $awd, 333, 2998, 6, 'automatic'],

            // ── BMW X5 F15 ──────────────────────────────────────────────
            // xDrive25d B47D20 1995 a 231 ch a partir de 08/2015 (N47D20
            // 218 ch avant) ; la valeur tardive est retenue, les F15 de
            // 2016-2018 etant celles qui s'importent. xDrive30d N57D30 2993.
            // xDrive35i N55B30 2979. xDrive50i N63B44 4395, V8 : la seule
            // huit cylindres de la F15 posee, pour le parc venu du Golfe.
            ['bmw', 'x5-f15', 'xDrive25d',        '25dX', 1, $diesel, $awd, 231, 1995, 4, 'automatic'],
            ['bmw', 'x5-f15', 'xDrive30d',        '30dX', 2, $diesel, $awd, 258, 2993, 6, 'automatic'],
            ['bmw', 'x5-f15', 'xDrive35i',        '35iX', 3, $petrol, $awd, 306, 2979, 6, 'automatic'],
            ['bmw', 'x5-f15', 'xDrive50i',        '50iX', 4, $petrol, $awd, 450, 4395, 8, 'automatic'],

            // ── BMW X5 E70 ──────────────────────────────────────────────
            // Nomenclature d'APRES mars 2008 : jusque-la les memes voitures
            // se nommaient 3.0d, 3.0si, 3.0sd et 4.8i. Un vendeur tenant une
            // E70 de 2007 cherchera ces noms-la et ne les trouvera pas ; le
            // choix est delibere, les millesimes qui s'importent aujourd'hui
            // etant ceux de 2009 et au-dela.
            // xDrive30d N57D30OL 2993 a 245 ch a partir de 06/2010 (M57D30TU2
            // 235 ch avant). xDrive30i N52B30 2996 atmospherique.
            // xDrive35d M57D30TU2 biturbo a 286 ch en Europe contre 265 ch en
            // Amerique du Nord ; 286 retenu, le diesel de cette generation
            // venant d'Europe, ou il s'est vendu sans commune mesure.
            // xDrive35i N55B30 2979. xDrive48i N62B48TU 4799, V8.
            ['bmw', 'x5-e70', 'xDrive30d',        '30dX', 1, $diesel, $awd, 245, 2993, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive30i',        '30iX', 2, $petrol, $awd, 272, 2996, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive35d',        '35dX', 3, $diesel, $awd, 286, 2993, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive35i',        '35iX', 4, $petrol, $awd, 306, 2979, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive48i',        '48iX', 5, $petrol, $awd, 355, 4799, 8, 'automatic'],

            // ════════════════════════════════════════════════════════════════
            // LEXUS — 23 generations, aucune finition jusqu'ici
            // ════════════════════════════════════════════════════════════════
            //
            // POURQUOI CE LOT. Lexus etait la plus grosse marque du referentiel
            // sans la moindre version : 23 lignes de `vehicle_models`, zero
            // ligne de `trims`. Un vendeur qui saisissait une RX ne pouvait
            // choisir AUCUNE finition. Meme blocage que « la BMW 528i n'existe
            // pas », mais sur 23 generations d'un coup.
            //
            // LA MODELISATION, qui n'est PAS celle de Toyota. Ici le modele est
            // la SERIE SEULE — RX, IS, ES, GS, LS, LX, GX, NX, UX, CT, RC — et
            // le code chassis sert de generation (`VehicleModelsSeeder`). Donc
            // « RX 350 » est une FINITION de la serie RX, exactement comme
            // « 528i » est une finition de la Serie 5. Aucun modele n'est cree
            // ici, et aucune generation ne manquait pour poser ces lignes.
            //
            // LE PIEGE, pire que celui de BMW : le nombre ne suit ni la
            // cylindree ni une regle stable.
            //   · NX 200t et NX 300 sont le MEME 2,0 turbo 8AR-FTS, renomme au
            //     restylage de septembre 2017. Deux lignes, parce que le nom
            //     est le seul porteur visible et qu'un vendeur tenant une 2016
            //     cherchera « 200t ». Meme traitement pour IS 200t / IS 300,
            //     GS 200t / GS 300 et RC 200t / RC 300.
            //   · IS 300 et RC 300 designent DEUX voitures differentes dans LE
            //     MEME marche : en propulsion c'est le 2,0 turbo 8AR-FTS de
            //     241 ch avec boite a huit rapports ; en integrale c'est un
            //     3,5 V6 atmospherique de 260 ch avec boite a six rapports.
            //     Lexus Amerique du Nord les vend cote a cote sous « IS 300 »
            //     et « IS 300 AWD » — c'est donc le nom qui porte la
            //     distinction, et ces lignes le reprennent tel quel.
            //   · Les hybrides portent « h » et leur nombre designe une
            //     PUISSANCE CUMULEE, pas une cylindree. Le piege se voit d'une
            //     generation a l'autre : la RX 450h de l'AL20 est un 3,5 V6,
            //     tandis que la RX 450h+ de l'AL30, qui porte presque le meme
            //     nom, est un 2,5 quatre cylindres. Et la RX 350h de l'AL30 est
            //     un 2,5 quand la RX 350 de l'AL20 est un 3,5 — meme nombre,
            //     deux cylindrees, deux generations. Les lignes ci-dessous
            //     portent donc toutes leur cylindree et leur code moteur.
            //
            // LE PERIMETRE : ce qui s'importe au Burkina Faso depuis l'AMERIQUE
            // DU NORD et le GOLFE. Quand deux marches donnent deux voitures
            // sous un nom, c'est celle de ces marches-la qui est retenue, et
            // l'autre est nommee dans le commentaire de la generation.
            //
            // LES SOURCES, par ligne, et il en faut deux :
            //   (1) la fiche Wikipedia du modele (tableaux par generation :
            //       designation, chassis, code moteur, marches) ;
            //   (2) la fiche Wikipedia de la FAMILLE de moteur — « Toyota GR
            //       engine », « Toyota AR engine », « Toyota UR engine »,
            //       « Toyota Dynamic Force engine », « Toyota UZ engine »,
            //       « Toyota MZ engine » — qui liste ses applications avec les
            //       annees. C'est un recoupement reellement independant sur le
            //       CODE MOTEUR, l'axe le plus facile a se tromper ;
            //   (3) pour les designations du Golfe, les fiches pays de
            //       DriveArabia (UAE / KSA / Oman / Bahrein) ;
            //   (4) pour les puissances et les motricites nord-americaines,
            //       Kelley Blue Book, Cars.com, Consumer Guide, Autoblog ;
            //   (5) et surtout `FiltresHuileBoutiqueSeeder` DU DEPOT, qui porte
            //       deja les codes moteur de ces generations, verifies par une
            //       relecture adversariale. C'est la seule source dont on sait
            //       ici qu'elle a ete controlee, et chaque bloc ci-dessous dit
            //       si elle confirme, si elle est muette, ou si elle diverge.
            //
            // CE QUE LA MOTRICITE PAR DEFAUT COMMANDE REELLEMENT, et c'est
            // mesure : `OwnedVehicle::getEffectiveDrivetrainIdAttribute()` rend
            // `$this->drivetrain_id ?? $this->trim?->default_drivetrain_id`, et
            // `CompatibilityService::partsForCriteria()` filtre les fitments sur
            // `drivetrain_id IS NULL OR = X`. Les lignes YZZA3 du catalogue de
            // filtres sont posees en RWD et les YZZA5 en AWD. Donc la motricite
            // par defaut de CES finitions-ci decide quelle cartouche a huile un
            // client voit sur une IS, une GS, une LS ou une RC.
            //   C'est pourquoi les IS, GS, RC et LS sont en PROPULSION, qui est
            //   leur architecture. Mesure sur une base jetable : une IS XE20 de
            //   2010 en finition « IS 250 » rend la YZZA3 et elle seule — avant
            //   ce lot, sans finition donc sans motricite, elle rendait les
            //   deux cartouches. Une LS XF40 de 2010 en « LS 460 » rend la
            //   YZZA5, qui est la sienne. Une CT 200h rend la YZZA6.
            //   Les lignes RX, ES, NX, UX, CT, LX et GX du catalogue ne portent
            //   AUCUNE motricite : pour elles le defaut ne cache ni ne revele
            //   aucune piece, il ne fait qu'epargner une saisie.
            //
            // CE QUE LE DEFAUT NE SAIT PAS FAIRE, et il faut le savoir avant de
            // s'y fier : une finition ne porte PAS de code moteur. La motricite
            // affine, elle ne tranche pas. Mesure, toujours sur base jetable :
            // une « IS 300 AWD » de 2019, qui est un 3,5 V6, se voit proposer
            // la YZZA5 — la sienne — MAIS AUSSI la YZZA1, qui est la cartouche
            // du 2,0 turbo, parce que la ligne YZZA1 de la XE30 ne porte pas de
            // motricite et que rien ne dit a la requete quel moteur est sous le
            // capot. De meme une « GS 460 » V8 de 2009 recoit la YZZA5, qui est
            // juste, et la YZZA3 des V6, qui ne l'est pas.
            //   Ce n'est pas une regression — ces generations n'avaient AUCUNE
            //   finition avant ce lot, donc aucune de ces reponses n'existait —
            //   et c'est une proposition, pas une prescription. Mais tant que
            //   `trims` n'a pas de code moteur, c'est la description qui dit au
            //   vendeur laquelle choisir, et c'est la sa deuxieme raison d'etre.
            //
            // CE QUE LA DESCRIPTION DIT DE LA MOTRICITE, et il faut le lire
            // ainsi : quand DEUX motricites existent SOUS LE MEME NOM — ce qui
            // est le cas de la plupart de ces Lexus, une RX 350 etant en
            // traction en Amerique du Nord et en integrale au Golfe — la
            // description les nomme TOUTES LES DEUX, la valeur par defaut en
            // premier (« integrale ou traction »). Elle n'annonce une seule
            // motricite que la ou il n'y en a qu'une.
            //   Le vendeur garde la main de toute facon :
            //   `OwnedVehicle::getEffectiveDrivetrainIdAttribute()` fait primer
            //   `drivetrain_id` du vehicule sur le defaut de la finition.
            //   Les deux SEULES lignes du lot ou la motricite est dans le NOM
            //   sont « IS 300 AWD » et « RC 300 AWD », parce que Lexus Amerique
            //   du Nord les nomme ainsi au configurateur, et parce que sans ce
            //   suffixe il faudrait choisir un moteur pour l'autre voiture.
            //
            // LE RANG va du plus modeste au plus puissant, par PUISSANCE
            // CUMULEE. Il y a donc des voisinages contre-intuitifs : la
            // GS 430 V8 de 290 ch passe AVANT la GS 350 V6 de 303 ch, et la
            // RX 400h de 268 ch avant la RX 350 de 270 ch — deux chevaux
            // d'ecart. C'est la regle appliquee, pas une inversion.
            //
            // NOTE DE LECTURE SUR LES BOITES : toutes les hybrides a train
            // epicycloidal sont notees 'cvt'. Deux exceptions assumees, la
            // RX 500h et la LX 700h, qui sont des hybrides paralleles a boite
            // mecanique (six et dix rapports) et sont notees 'automatic'.

            // ── LEXUS RX AL30 (2022→) ────────────────────────────────────
            // Plus aucun V6 : les quatre versions sont des quatre cylindres.
            // Le catalogue de filtres du depot confirme la seule qu'il couvre,
            // la RX 350h en A25A-FXS a partir de 2023 (tableau YZZN1 Lexus,
            // « Confirme Lexus »). Il est MUET sur la RX 350, la 450h+ et la
            // 500h ; ce n'est pas une contradiction, il ne couvre que les
            // filtres a huile et le T24A-FTS n'y figure pour aucun vehicule.
            // La RX 350 a la TRACTION pour defaut : c'est le montage de serie
            // en Amerique du Nord, l'integrale y etant une option sauf sur la
            // F Sport Handling — et les deux portent le meme nom, d'ou le
            // « traction ou integrale ». Les trois hybrides sont en integrale
            // seule. Une fiche Edmunds de millesime 2024 donne au contraire
            // l'integrale de serie sur la RX 350 ; TrueCar et TrueDelta la
            // donnent en traction sur 2023, et c'est cette lecture qui est
            // retenue, deux sources contre une.
            ['lexus', 'rx-al30', 'RX 350h',    '350h',  1, $hybrid, $awd, 246, 2487, 4, 'cvt',       '2,5 4 cylindres hybride A25A-FXS, 246 ch cumulés, intégrale'],
            ['lexus', 'rx-al30', 'RX 350',     '350',   2, $petrol, $fwd, 275, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, 275 ch, traction ou intégrale'],
            ['lexus', 'rx-al30', 'RX 450h+',   '450h+', 3, $hybrid, $awd, 304, 2487, 4, 'cvt',       '2,5 4 cylindres hybride rechargeable A25A-FXS, 304 ch cumulés, intégrale'],
            ['lexus', 'rx-al30', 'RX 500h',    '500h',  4, $hybrid, $awd, 366, 2393, 4, 'automatic', '2,4 4 cylindres turbo hybride T24A-FTS, 366 ch cumulés, intégrale'],

            // ── LEXUS RX AL20 (2015-2022) ────────────────────────────────
            // Les QUATRE codes moteur de cette generation sont tous poses par
            // le catalogue de filtres sur `rx-al20` : 2GR-FE 2015-2022 et
            // 2GR-FKS 2016-2022, 2GR-FXE 2015-2022 et 2GR-FXS 2017-2022. Le
            // catalogue ne distingue pas les finitions, donc les « L » heritent
            // des memes codes que les versions courtes, qu'elles partagent.
            // La RX 350 traverse deux codes moteur sous un seul nom, et le
            // catalogue date la bascule a 2016 ; la description porte les deux.
            //
            // LA RX 200t / RX 300 EST ECARTEE, et c'est le seul retrait de
            // cette generation : le 2,0 turbo 8AR-FTS n'a jamais ete vendu en
            // Amerique du Nord, et les listes de prix du Golfe de DriveArabia
            // (UAE 2017 et 2018, KSA 2018) ne donnent que RX 350 et RX 450h.
            // Une seule annonce UAE isolee la cite ; cela ne suffit pas.
            //
            // Les deux « L » a sept places sont posees : ce sont des
            // designations distinctes au hayon et sur la carte grise, et la
            // RX 350L n'a pas la meme puissance que la RX 350 — 290 ch contre
            // 295, meme 2GR-FKS (Kelley Blue Book 2019).
            //
            // Motricites : les deux HYBRIDES de cette generation sont en
            // integrale SEULE sur le marche nord-americain, verifie sur les
            // fiches 2018 de Cars.com et d'iSeeCars pour la RX 450h et sur
            // Carscoops pour la RX 450hL (« les deux finitions avec integrale
            // de serie »). Les deux essence existent dans les deux motricites
            // sous le meme nom, d'ou le « integrale ou traction ».
            ['lexus', 'rx-al20', 'RX 350L',    '350L',  1, $petrol, $awd, 290, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 290 ch, intégrale ou traction, sept places'],
            ['lexus', 'rx-al20', 'RX 350',     '350',   2, $petrol, $awd, 295, 3456, 6, 'automatic', '3,5 V6 2GR-FE, 295 ch, intégrale ou traction — 2GR-FKS sur les derniers millésimes, bascule non datée par le catalogue'],
            ['lexus', 'rx-al20', 'RX 450h',    '450h',  3, $hybrid, $awd, 308, 3456, 6, 'cvt',       '3,5 V6 hybride 2GR-FXE puis 2GR-FXS, 308 ch cumulés, intégrale'],
            ['lexus', 'rx-al20', 'RX 450hL',   '450hL', 4, $hybrid, $awd, 308, 3456, 6, 'cvt',       '3,5 V6 hybride 2GR-FXS, 308 ch cumulés, intégrale, sept places'],

            // ── LEXUS RX AL10 (2009-2015) ────────────────────────────────
            // Deux versions, toutes deux confirmees par le catalogue de
            // filtres : 2GR-FE 2009-2015 (RX 350) et 2GR-FXE 2010-2015
            // (RX 450h).
            //   Une nuance sur la motricite : le document du depot qualifie sa
            //   ligne RX 450h d'AWD, alors que la fiche du modele liste aussi
            //   une RX 450h en traction (chassis GLV10) a cote de l'integrale
            //   (GLV15). L'integrale est donc le defaut et la traction est
            //   nommee a cote. Cela n'a aucun effet sur la piece rendue : la
            //   ligne de compatibilite ne porte PAS de motricite, le mot
            //   « AWD » n'est que dans sa provenance en clair.
            //
            // LA RX 270 EST ECARTEE : son 2,7 quatre cylindres 1AR-FE est un
            // moteur du Japon, de la Chine, de la Russie et de l'Australie.
            // Ni l'Amerique du Nord ni le Golfe ne l'ont recu.
            ['lexus', 'rx-al10', 'RX 350',     '350',   1, $petrol, $awd, 275, 3456, 6, 'automatic', '3,5 V6 2GR-FE, 275 ch, intégrale ou traction'],
            ['lexus', 'rx-al10', 'RX 450h',    '450h',  2, $hybrid, $awd, 295, 3456, 6, 'cvt',       '3,5 V6 hybride 2GR-FXE, 295 ch cumulés, intégrale ou traction'],

            // ── LEXUS RX XU30 (2003-2009) ────────────────────────────────
            // Le catalogue de filtres ne couvre que la RX 350 de cette
            // generation (2GR-FE 2007-2009, chassis GSU35). Il est muet sur le
            // 3MZ-FE de la RX 330 et de la RX 400h — c'est une famille MZ, que
            // le document ne traite pas ; ce n'est pas une contradiction.
            //
            // LA RX 300 XU30 EST ECARTEE : son 1MZ-FE etait reserve a l'Europe,
            // a Singapour, a la Coree du Sud et a Taiwan.
            ['lexus', 'rx-xu30', 'RX 330',     '330',   1, $petrol, $awd, 230, 3310, 6, 'automatic', '3,3 V6 3MZ-FE, 230 ch, intégrale ou traction'],
            ['lexus', 'rx-xu30', 'RX 400h',    '400h',  2, $hybrid, $awd, 268, 3310, 6, 'cvt',       '3,3 V6 hybride 3MZ-FE, 268 ch cumulés, intégrale ou traction'],
            ['lexus', 'rx-xu30', 'RX 350',     '350',   3, $petrol, $awd, 270, 3456, 6, 'automatic', '3,5 V6 2GR-FE, 270 ch, intégrale ou traction'],

            // ── LEXUS ES XV70 (2018→) ────────────────────────────────────
            // Les trois versions du Golfe, lues sur la liste de prix UAE 2019
            // de DriveArabia : ES 250 (2,5 de 204 ch), ES 300h (215 ch) et
            // ES 350 (3,5 V6 de 301 ch). Ce sont aussi les trois d'Amerique du
            // Nord, a une nuance pres : la ES 250 nord-americaine n'existe
            // qu'en INTEGRALE et seulement a partir du millesime 2021, alors
            // que celle du Golfe est en traction des 2019. La traction est
            // retenue, parce que c'est la voiture du Golfe et que c'est aussi
            // le montage des deux autres versions.
            //
            // Le catalogue de filtres confirme les deux quatre cylindres —
            // A25A-FXS 2019+ et A25A-FKS 2021+ — et sa borne de 2021 sur la
            // ES 250 est justement la borne nord-americaine. Sur la ES 350 il
            // est MUET pour cette generation, alors que sa ligne « ES 350 »
            // cite le chassis GSV70, qui EST la XV70 : la ligne du depot est
            // bornee a es-xv40 et es-xv60. C'est un manque a rapporter, pas une
            // contradiction, et ce n'est pas a ce lot-ci de le combler.
            ['lexus', 'es-xv70', 'ES 250',     '250',   1, $petrol, $fwd, 204, 2487, 4, 'automatic', '2,5 4 cylindres A25A-FKS, 204 ch, traction ou intégrale'],
            ['lexus', 'es-xv70', 'ES 300h',    '300h',  2, $hybrid, $fwd, 215, 2487, 4, 'cvt',       '2,5 4 cylindres hybride A25A-FXS, 215 ch cumulés, traction'],
            ['lexus', 'es-xv70', 'ES 350',     '350',   3, $petrol, $fwd, 301, 3456, 6, 'automatic', '3,5 V6 2GR-FKS, 301 ch, traction'],

            // ── LEXUS ES XV60 (2012-2018) ────────────────────────────────
            // Les trois codes moteur sont confirmes un a un par le catalogue de
            // filtres : 2GR-FE 2012-2018 et 2GR-FKS 2016-2018 pour la ES 350,
            // 2AR-FXE 2013-2018 pour la ES 300h.
            // La ES 250 est en revanche posee SANS que le catalogue la couvre :
            // il cite le 2AR-FE dans son index par code moteur, mais ne le
            // rattache pas a cette generation. Son existence au Golfe est
            // etablie par DriveArabia (UAE et KSA 2015, « deux niveaux
            // desormais, ES 250 et ES 350 »), et le code 2AR-FE par la fiche
            // de la famille AR, qui donne « Lexus ES 250 (ASV60) ».
            //
            // La ES 240 est ECARTEE : 2AZ-FE de Chine et d'Asie seulement.
            ['lexus', 'es-xv60', 'ES 250',     '250',   1, $petrol, $fwd, 181, 2494, 4, 'automatic', '2,5 4 cylindres 2AR-FE, 181 ch, traction'],
            ['lexus', 'es-xv60', 'ES 300h',    '300h',  2, $hybrid, $fwd, 200, 2494, 4, 'cvt',       '2,5 4 cylindres hybride 2AR-FXE, 200 ch cumulés, traction'],
            ['lexus', 'es-xv60', 'ES 350',     '350',   3, $petrol, $fwd, 268, 3456, 6, 'automatic', '3,5 V6 2GR-FE, 268 ch, traction — 2GR-FKS sur les derniers millésimes, bascule non datée par le catalogue'],

            // ── LEXUS ES XV40 (2006-2012) ────────────────────────────────
            // UNE SEULE version pour cette generation, et c'est deliberé : la
            // XV40 n'a recu que la ES 350 en Amerique du Nord et au Golfe —
            // Wikipedia nomme explicitement le Moyen-Orient parmi ses marches
            // de lancement en 2007. La ES 240 (2AZ-FE) etait chinoise et
            // asiatique. Le catalogue de filtres confirme : 2GR-FE 2007-2012.
            ['lexus', 'es-xv40', 'ES 350',     '350',   1, $petrol, $fwd, 272, 3456, 6, 'automatic', '3,5 V6 2GR-FE, 272 ch, traction'],

            // ── LEXUS IS XE30 (2013→) ────────────────────────────────────
            // LA GENERATION OU LE NOM MENT, et six lignes pour le dire.
            // « IS 300 » y designe DEUX voitures vendues cote a cote :
            //   · en propulsion, le 2,0 turbo 8AR-FTS de 241 ch, boite a huit
            //     rapports — c'est l'IS 200t de 2016-2017 renommee au
            //     millesime 2018, et DriveArabia le dit mot pour mot sur sa
            //     page UAE 2019 : « the IS200t, later renamed to IS300 » ;
            //   · en integrale, un 3,5 V6 atmospherique de 260 ch, boite a six
            //     rapports (Autoblog et Kelley Blue Book, millesimes 2018-2024).
            // Les deux sont posees, sous les deux noms du configurateur
            // nord-americain : « IS 300 » et « IS 300 AWD ». Un seul nom aurait
            // force a choisir un moteur pour l'autre voiture.
            //
            // Le catalogue de filtres ne connait QUE la premiere : son tableau
            // YZZA1 donne « IS 300 / RC 300 / IS 200t, chassis ASE30 / ASC10,
            // 2.0 turbo 8AR-FTS 2016-2021 RWD ». La seconde releve de ses
            // lignes YZZA5 en integrale (4GR-FSE et 2GR-FSE), ou le document
            // ecarte justement le 2GR-FKS comme « Conditionnel » sur 2016-2020 —
            // statut qui ne dit RIEN de l'existence du moteur, seulement de la
            // confiance du document sur la cartouche a lui associer.
            // D'ou la motricite portee ici : « IS 300 AWD » en integrale tombe
            // sur la YZZA5, « IS 300 » en propulsion sur la YZZA1 et la YZZA3.
            //
            // L'IS 300h est ECARTEE : hybride 2AR-FSE d'Europe et du Japon,
            // jamais vendue en Amerique du Nord ni au Golfe.
            ['lexus', 'is-xe30', 'IS 250',     '250',   1, $petrol, $rwd, 204, 2499, 6, 'automatic', '2,5 V6 4GR-FSE, 204 ch, propulsion ou intégrale'],
            ['lexus', 'is-xe30', 'IS 200t',    '200t',  2, $petrol, $rwd, 241, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 241 ch, propulsion'],
            ['lexus', 'is-xe30', 'IS 300',     '300',   3, $petrol, $rwd, 241, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 241 ch, propulsion — l\'IS 200t renommée en 2018'],
            ['lexus', 'is-xe30', 'IS 300 AWD', '300X',  4, $petrol, $awd, 260, 3456, 6, 'automatic', '3,5 V6 2GR-FSE puis 2GR-FKS, 260 ch, intégrale — rien du 2,0 turbo que porte l\'IS 300 propulsion'],
            ['lexus', 'is-xe30', 'IS 350',     '350',   5, $petrol, $rwd, 311, 3456, 6, 'automatic', '3,5 V6 2GR-FSE puis 2GR-FKS en 2018, 311 ch, propulsion ou intégrale'],
            ['lexus', 'is-xe30', 'IS 500',     '500',   6, $petrol, $rwd, 472, 4969, 8, 'automatic', '5,0 V8 2UR-GSE, 472 ch, propulsion'],

            // ── LEXUS IS XE20 (2005-2013) ────────────────────────────────
            // L'IS 300 de CETTE generation est une tout autre voiture que celle
            // de la XE30 : un 3,0 V6 atmospherique 3GR-FE de 228 ch, chassis
            // GSE22, et c'est la version DU GOLFE — DriveArabia le confirme sur
            // ses pages 2008 d'Arabie saoudite, des Emirats, du Koweit, du
            // Qatar et de Bahrein : « 3.0-litre V6, 228 hp, propulsion, seul
            // moteur du marche local ». Le code 3GR-FE est donne par la fiche
            // de la famille GR (« IS 300 (GSE22), 2007 »).
            //   Autrement dit le meme nom couvre, sur deux generations
            //   voisines, un 3,0 V6 et un 2,0 turbo. C'est pourquoi la
            //   description est precieuse ici.
            //
            // Le catalogue de filtres confirme les deux autres : 4GR-FSE et
            // 2GR-FSE, 2006-2013, en propulsion (YZZA3) comme en integrale
            // (YZZA5). Il est muet sur le 3GR-FE et sur l'IS F.
            //
            // Les IS 220d et IS 200d sont ECARTEES : diesels 2AD europeens.
            //
            // LE BORD DE CETTE GENERATION, mesure : les QUATRE finitions
            // ci-dessous rendent la meme YZZA3 sur un millesime 2010, parce
            // qu'elles partagent la propulsion et que la ligne YZZA3 ne
            // distingue pas les moteurs. C'est juste pour l'IS 250 et l'IS 350,
            // que le document nomme ; ce ne l'est pas pour l'IS F, dont le V8
            // 2UR-GSE n'est dans aucune famille du catalogue. Un vendeur
            // d'IS F lira la description — « 5,0 V8 2UR-GSE » — et verra que
            // la cartouche proposee n'est pas la sienne.
            ['lexus', 'is-xe20', 'IS 250',     '250',   1, $petrol, $rwd, 204, 2499, 6, 'automatic', '2,5 V6 4GR-FSE, 204 ch, propulsion ou intégrale'],
            ['lexus', 'is-xe20', 'IS 300',     '300',   2, $petrol, $rwd, 228, 2994, 6, 'automatic', '3,0 V6 3GR-FE, 228 ch, propulsion — version du Golfe'],
            ['lexus', 'is-xe20', 'IS 350',     '350',   3, $petrol, $rwd, 306, 3456, 6, 'automatic', '3,5 V6 2GR-FSE, 306 ch, propulsion ou intégrale'],
            ['lexus', 'is-xe20', 'IS F',       'F',     4, $petrol, $rwd, 416, 4969, 8, 'automatic', '5,0 V8 2UR-GSE, 416 ch, propulsion'],

            // ── LEXUS GS L10 (2012-2020) ─────────────────────────────────
            // Meme renommage que sur l'IS et la NX : la GS 200t devient GS 300
            // au millesime 2018 nord-americain, meme 8AR-FTS de 241 ch. Les
            // deux noms sont poses — les listes de prix du Golfe de DriveArabia
            // portent encore « GS 200t » en 2017 et 2019.
            //
            // Le catalogue de filtres confirme le 2GR-FSE de la GS 350 sur les
            // deux motricites, mais pas sur la meme plage : 2012-2015 en
            // propulsion (YZZA3) et 2012-2020 en integrale (YZZA5) — l'ecart
            // vient du document, qui s'arrete a 2015 sur une famille et va
            // jusqu'a 2020 sur l'autre. Il ecarte le 2GR-FKS, donne
            // « Conditionnel » — statut portant sur la cartouche a associer, non
            // sur l'existence du moteur. Il est muet sur le 8AR-FTS de cette
            // generation — alors qu'il le connait pour l'IS et la RC — et sur
            // le 2UR-GSE de la GS F.
            //
            // La GS 250 est ECARTEE (Chine, Japon, Australie, Royaume-Uni ;
            // jamais les Etats-Unis), la GS 300h aussi (hybride 2AR-FSE
            // d'Europe et d'Asie).
            ['lexus', 'gs-l10',  'GS 200t',    '200t',  1, $petrol, $rwd, 241, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 241 ch, propulsion'],
            ['lexus', 'gs-l10',  'GS 300',     '300',   2, $petrol, $rwd, 241, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 241 ch, propulsion — la GS 200t renommée en 2018'],
            ['lexus', 'gs-l10',  'GS 350',     '350',   3, $petrol, $rwd, 311, 3456, 6, 'automatic', '3,5 V6 2GR-FSE puis 2GR-FKS en 2016, 311 ch, propulsion ou intégrale'],
            ['lexus', 'gs-l10',  'GS 450h',    '450h',  4, $hybrid, $rwd, 338, 3456, 6, 'cvt',       '3,5 V6 hybride 2GR-FXE, 338 ch cumulés, propulsion'],
            ['lexus', 'gs-l10',  'GS F',       'F',     5, $petrol, $rwd, 467, 4969, 8, 'automatic', '5,0 V8 2UR-GSE, 467 ch, propulsion'],

            // ── LEXUS GS S190 (2005-2012) ────────────────────────────────
            // Cinq versions, et le rang par puissance met la GS 430 V8 de
            // 290 ch AVANT la GS 350 V6 de 303 ch. C'est voulu.
            //
            // Le catalogue de filtres confirme trois des cinq codes moteur sur
            // cette generation precise, chassis a l'appui : 3GR-FSE et 2GR-FSE
            // (GRS190 / GRS191, YZZA3), et 1UR-FSE pour la GS 460 (URS190,
            // YZZA5). Il est muet sur le 3UZ-FE de la GS 430, famille UZ qu'il
            // ne traite pas.
            //
            // UNE DIVERGENCE A RAPPORTER, et elle ne se tranche pas ici : la
            // fiche de la famille UR donne la GS 460 DU MOYEN-ORIENT en
            // 1UR-FE — injection indirecte, 322 ch — et non en 1UR-FSE. Or le
            // 1UR-FE releve, dans le catalogue du depot, de la famille 9 et de
            // la cartouche 04152-YZZA4, pas de la YZZA5. Une GS 460 venue du
            // Golfe se verrait donc proposer la mauvaise cartouche. La ligne de
            // compatibilite existante n'est pas touchee : le document
            // constructeur dit URS190 → 1UR-FSE, et c'est au proprietaire de
            // decider si la variante du Golfe merite sa propre ligne.
            ['lexus', 'gs-s190', 'GS 300',     '300',   1, $petrol, $rwd, 245, 2994, 6, 'automatic', '3,0 V6 3GR-FSE, 245 ch, propulsion ou intégrale'],
            ['lexus', 'gs-s190', 'GS 430',     '430',   2, $petrol, $rwd, 290, 4292, 8, 'automatic', '4,3 V8 3UZ-FE, 290 ch, propulsion'],
            ['lexus', 'gs-s190', 'GS 350',     '350',   3, $petrol, $rwd, 303, 3456, 6, 'automatic', '3,5 V6 2GR-FSE, 303 ch, propulsion ou intégrale'],
            ['lexus', 'gs-s190', 'GS 450h',    '450h',  4, $hybrid, $rwd, 339, 3456, 6, 'cvt',       '3,5 V6 hybride 2GR-FSE, 339 ch cumulés, propulsion'],
            ['lexus', 'gs-s190', 'GS 460',     '460',   5, $petrol, $rwd, 342, 4608, 8, 'automatic', '4,6 V8 1UR-FSE, 342 ch, propulsion — variante du Golfe en 1UR-FE, autre cartouche'],

            // ── LEXUS LS XF50 (2017→) ────────────────────────────────────
            // Deux versions, et le catalogue de filtres est MUET sur cette
            // generation entiere : ni le V35A-FTS ni le 8GR-FXS n'y figurent
            // pour aucun vehicule. Les deux codes sont donc recoupes hors
            // depot — fiche Wikipedia du modele et fiches des familles
            // « Dynamic Force » (V35A-FTS → LS 500, 2017→) et GR (8GR-FXS →
            // LS 500h, 2017).
            //
            // La LS 350 est ECARTEE : 2GR-FKS de Chine et de Hong Kong.
            //
            // La LS 500h est notee 'cvt' par defaut de mieux : sa transmission
            // « Multi Stage Hybrid » est un train epicycloidal suivi d'une
            // boite a quatre rapports, et l'enum du depot ne connait que
            // manual / automatic / cvt.
            ['lexus', 'ls-xf50', 'LS 500h',    '500h',  1, $hybrid, $rwd, 354, 3456, 6, 'cvt',       '3,5 V6 hybride 8GR-FXS, 354 ch cumulés, propulsion ou intégrale'],
            ['lexus', 'ls-xf50', 'LS 500',     '500',   2, $petrol, $rwd, 416, 3445, 6, 'automatic', '3,5 V6 biturbo V35A-FTS, 416 ch, propulsion ou intégrale'],

            // ── LEXUS LS XF40 (2006-2017) ────────────────────────────────
            // Le catalogue de filtres couvre les deux moteurs : 1UR-FSE pour la
            // LS 460 (chassis USF40) et 2UR-FSE pour la LS 600h (chassis
            // UVF45), 2007-2017, sans motricite — donc les trois lignes
            // ci-dessous voient leur cartouche quelle que soit la motricite.
            //
            // MEME DIVERGENCE QUE SUR LA GS 460, et meme refus de trancher : la
            // fiche de la famille UR donne la LS 460 DU MOYEN-ORIENT en
            // 1UR-FE, qui releve d'une autre cartouche dans le document du
            // depot. Rapporte, pas corrige.
            //
            // La LS 600h L est posee et pas la LS 600h courte : seule la longue
            // a ete vendue en Amerique du Nord. La LS 460 L est posee parce que
            // c'est une designation distincte sur la carte grise, pas un
            // equipement.
            ['lexus', 'ls-xf40', 'LS 460',     '460',   1, $petrol, $rwd, 380, 4608, 8, 'automatic', '4,6 V8 1UR-FSE, 380 ch, propulsion ou intégrale — variante du Golfe en 1UR-FE, autre cartouche'],
            ['lexus', 'ls-xf40', 'LS 460 L',   '460L',  2, $petrol, $rwd, 380, 4608, 8, 'automatic', '4,6 V8 1UR-FSE, 380 ch, propulsion ou intégrale, empattement long — variante du Golfe en 1UR-FE, autre cartouche'],
            ['lexus', 'ls-xf40', 'LS 600h L',  '600hL', 3, $hybrid, $awd, 438, 4969, 8, 'cvt',       '5,0 V8 hybride 2UR-FSE, 438 ch cumulés, intégrale, empattement long'],

            // ── LEXUS LX J310 (2021→) ────────────────────────────────────
            // La LX 500d EST ECARTEE, et c'est le retrait le plus discutable du
            // lot : son 3,3 V6 diesel F33A-FTV est documente par la fiche du
            // modele, mais la gamme officielle du Golfe n'en porte pas trace —
            // Al-Futtaim aux Emirats annonce LX 700h et LX 600 Overtrail, et la
            // gamme saoudienne tient en « cinq finitions, deux moteurs », le
            // 3,4 essence et l'hybride. Les seules traces sont des annonces
            // d'exportateurs de Dubai, dont plusieurs en CONDUITE A DROITE :
            // des voitures du Japon ou d'Australie en transit, pas des
            // importations pour le Burkina Faso, qui roule a droite.
            //
            // Motricite en 4WD, comme le Land Cruiser J200 et le Prado J150 de
            // ce meme fichier — c'est la convention du depot pour ces chassis.
            // Le catalogue de filtres ne pose AUCUNE compatibilite sur la LX,
            // ni sur l'une ni sur l'autre generation, donc ce choix ne peut
            // cacher aucune piece : il n'y en a aucune a cacher.
            ['lexus', 'lx-j310', 'LX 600',     '600',   1, $petrol, $dwa, 409, 3445, 6, 'automatic', '3,4 V6 biturbo V35A-FTS, 409 ch, quatre roues motrices'],
            ['lexus', 'lx-j310', 'LX 700h',    '700h',  2, $hybrid, $dwa, 457, 3445, 6, 'automatic', '3,4 V6 biturbo hybride V35A-FTS, 457 ch cumulés, quatre roues motrices'],

            // ── LEXUS LX J200 (2007-2021) ────────────────────────────────
            // Les deux moteurs sont nommes par le catalogue de filtres du
            // depot, et c'est le seul endroit du referentiel ou il parle de la
            // LX : le 3UR-FE de la LX 570 y est « Documente » sous la cartouche
            // 04152-YZZA4, et la 04152-51010 est decrite comme « l'element
            // filtrant du V8 diesel 1VD-FTV : Land Cruiser 200 et 70 V8, Lexus
            // LX 450d ». Aucune compatibilite n'est posee pour autant — le
            // document les classe « Documente » ou « Probable », pas
            // « Confirme » — donc ces deux finitions ne feront apparaitre aucun
            // filtre tant que le proprietaire n'en decide pas autrement. Ce
            // n'est pas une regression : elles n'existaient pas avant.
            //
            // La LX 570 nord-americaine est donnee a 383 ch et celle du Golfe a
            // 362 ch, meme moteur ; 383 est retenu, et la fiche de la famille
            // UR donne 381 pour le 3UR-FE — trois sources, trois chiffres a
            // deux chevaux pres.
            //
            // Les LX 460 et LX 470 de cette generation sont ECARTEES : vendues
            // a Hong Kong seulement.
            ['lexus', 'lx-j200', 'LX 450d',    '450d',  1, $diesel, $dwa, 268, 4461, 8, 'automatic', '4,5 V8 turbodiesel 1VD-FTV, 268 ch, quatre roues motrices'],
            ['lexus', 'lx-j200', 'LX 570',     '570',   2, $petrol, $dwa, 383, 5663, 8, 'automatic', '5,7 V8 3UR-FE, 383 ch, quatre roues motrices'],

            // ── LEXUS GX J250 (2023→) / J150 (2009-2023) / J120 (2002-2009)
            // Une seule version par generation, et ce n'est pas un oubli : le
            // GX n'a jamais eu qu'un moteur a la fois sur les marches qui nous
            // concernent. Le GX 400 (1GR-FE) est ECARTE, chinois uniquement, et
            // le GX 550h ne l'est pas moins faute de source : la fiche du
            // modele le mentionne en T24A-FTS mais ne lui donne ni puissance ni
            // marche, et aucune autre source consultee ne le confirme.
            //
            // LE GX 470 N'A JAMAIS ETE VENDU AU GOLFE — Gulf News l'ecrit
            // noir sur blanc en presentant le GX 460 : « le GX 470 n'etait pas
            // vendu sur notre marche ». Il reste pose parce qu'il tient sur
            // l'autre jambe du perimetre : c'est un modele nord-americain.
            //
            // Le catalogue de filtres est muet sur les trois, mais son index
            // par code moteur range le 1UR-FE du GX 460 en famille 9
            // (04152-YZZA4) — ce qui le confirme indirectement.
            //
            // Puissance du GX 470 : 263 ch est la valeur d'apres le millesime
            // 2006, quand la norme SAE a change. Les memes voitures etaient
            // annoncees a 270 ch avant, et a 235 ch au lancement de 2003.
            ['lexus', 'gx-j250', 'GX 550',     '550',   1, $petrol, $dwa, 349, 3445, 6, 'automatic', '3,4 V6 biturbo V35A-FTS, 349 ch, quatre roues motrices'],
            ['lexus', 'gx-j150', 'GX 460',     '460',   1, $petrol, $dwa, 301, 4608, 8, 'automatic', '4,6 V8 1UR-FE, 301 ch, quatre roues motrices'],
            ['lexus', 'gx-j120', 'GX 470',     '470',   1, $petrol, $dwa, 263, 4663, 8, 'automatic', '4,7 V8 2UZ-FE, 263 ch, quatre roues motrices'],

            // ── LEXUS NX AZ20 (2021→) ────────────────────────────────────
            // Le catalogue de filtres pose les deux codes A25A — FKS et FXS —
            // sur cette generation a partir de 2022, mais sous un libelle qui
            // GROUPE les trois versions sans les separer : « NX 250 / NX 350h /
            // NX 450h+ ». La repartition portee par les descriptions ci-dessous
            // — FKS a l'essence, FXS aux deux hybrides — vient de la fiche de
            // la famille Dynamic Force, pas du catalogue. Celui-ci est muet sur
            // le T24A-FTS de la NX 350.
            // Motricites lues sur Consumer Guide : la NX 250 existe en traction
            // et en integrale, les trois autres sont integrales seules.
            // La NX 200 (M20A-FKS, Taiwan) et la NX 400h+ (Chine) sont ECARTEES.
            ['lexus', 'nx-az20', 'NX 250',     '250',   1, $petrol, $fwd, 203, 2487, 4, 'automatic', '2,5 4 cylindres A25A-FKS, 203 ch, traction ou intégrale'],
            ['lexus', 'nx-az20', 'NX 350h',    '350h',  2, $hybrid, $awd, 239, 2487, 4, 'cvt',       '2,5 4 cylindres hybride A25A-FXS, 239 ch cumulés, intégrale'],
            ['lexus', 'nx-az20', 'NX 350',     '350',   3, $petrol, $awd, 275, 2393, 4, 'automatic', '2,4 4 cylindres turbo T24A-FTS, 275 ch, intégrale'],
            ['lexus', 'nx-az20', 'NX 450h+',   '450h+', 4, $hybrid, $awd, 302, 2487, 4, 'cvt',       '2,5 4 cylindres hybride rechargeable A25A-FXS, 302 ch cumulés, intégrale'],

            // ── LEXUS NX AZ10 (2014-2021) ────────────────────────────────
            // LE RENOMMAGE LE PLUS PIEGEUX DU LOT, et le depot le savait deja :
            // son index par code moteur range le 8AR-FTS sous « NX 200t /
            // NX 300, IS 300, RC 300 » — deux noms, un moteur. La fiche du
            // modele date la bascule du restylage de SEPTEMBRE 2017, et
            // DriveArabia l'ecrit sur sa page UAE 2018 : « le nom est passe de
            // NX 200t a NX 300 avec le restylage ». Deux lignes donc, meme
            // moteur, meme puissance, parce qu'un vendeur tenant une 2016
            // cherchera « 200t » et un autre tenant une 2019 cherchera « 300 ».
            //
            // La NX 300h est confirmee par le meme index — « 2AR-FE / 2AR-FXE →
            // YZZA1, Camry, RAV4, ES 300h, NX 300h ».
            //
            // La NX 200 (3ZR-FAE de Russie et de Chine) est ECARTEE.
            //
            // Motricite par DEFAUT en integrale pour les trois : DriveArabia
            // donne la NX 300 du Golfe en integrale de serie. En Amerique du
            // Nord la traction existe aussi, sous le MEME nom — le referentiel
            // ne peut donc pas les distinguer, d'ou le « integrale ou
            // traction » des descriptions.
            //   Et de toute facon rien n'est cache : le catalogue de filtres ne
            //   pose AUCUNE compatibilite sur `nx-az10`. Mesure sur base
            //   jetable : une NX 300 de 2019 ne rend AUCUNE cartouche. Le
            //   8AR-FTS et le 2AR-FXE de cette generation ne sont nommes que
            //   dans l'index par code moteur du document, jamais poses en
            //   ligne. C'est un manque du catalogue de filtres, a rapporter au
            //   proprietaire, et il est anterieur a ce lot.
            ['lexus', 'nx-az10', 'NX 300h',    '300h',  1, $hybrid, $awd, 194, 2494, 4, 'cvt',       '2,5 4 cylindres hybride 2AR-FXE, 194 ch cumulés, intégrale ou traction'],
            ['lexus', 'nx-az10', 'NX 200t',    '200t',  2, $petrol, $awd, 235, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 235 ch, intégrale ou traction'],
            ['lexus', 'nx-az10', 'NX 300',     '300',   3, $petrol, $awd, 235, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 235 ch, intégrale ou traction — la NX 200t renommée en 2017'],

            // ── LEXUS UX ZA10 (2018→) ────────────────────────────────────
            // Le catalogue de filtres confirme les deux codes M20A — FKS pour
            // la UX 200, FXS pour la UX 250h — a partir de 2019, et les nomme
            // ensemble sur la meme ligne.
            //
            // TROISIEME RENOMMAGE, et le plus tardif : la UX 250h devient
            // UX 300h au millesime 2025 nord-americain, avec un hybride plus
            // puissant — 181 ch deviennent 196. Ce n'est donc PAS le meme
            // groupe motopropulseur que les deux autres renommages de ce lot,
            // ou le moteur ne changeait pas. Les deux noms sont poses.
            //
            // Motricites : la UX 250h nord-americaine etait en INTEGRALE de
            // serie jusqu'au millesime 2022, la traction n'arrivant qu'en 2023
            // (Cars.com) ; l'integrale est donc retenue pour elle. La UX 200
            // n'a jamais existe qu'en traction, et la UX 300h est en traction
            // de serie, integrale en option.
            //
            // La UX 300e electrique est ECARTEE : Europe, Chine et Japon.
            //
            // La puissance de la UX 200 est donnee a 168 ch par l'infobox de la
            // fiche du modele et par la fiche de la famille Dynamic Force
            // (125 kW), mais a 171 ch par le tableau de la meme fiche. 168 est
            // retenu, deux sources contre une.
            ['lexus', 'ux-za10', 'UX 200',     '200',   1, $petrol, $fwd, 168, 1987, 4, 'cvt',       '2,0 4 cylindres M20A-FKS, 168 ch, traction'],
            ['lexus', 'ux-za10', 'UX 250h',    '250h',  2, $hybrid, $awd, 181, 1987, 4, 'cvt',       '2,0 4 cylindres hybride M20A-FXS, 181 ch cumulés, intégrale ou traction'],
            ['lexus', 'ux-za10', 'UX 300h',    '300h',  3, $hybrid, $fwd, 196, 1987, 4, 'cvt',       '2,0 4 cylindres hybride M20A-FXS, 196 ch cumulés, traction ou intégrale — la UX 250h renommée en 2025'],

            // ── LEXUS CT ZWA10 (2010-2022) ───────────────────────────────
            // UNE SEULE version, et elle est triplement etablie : la CT n'a
            // jamais existe qu'en CT 200h, le catalogue de filtres du depot la
            // nomme explicitement — « Lexus CT 200h 1.8 2ZR-FXE 2011-2017 »,
            // statut « Confirme Lexus » — et la fiche du modele donne le meme
            // 2ZR-FXE. Lexus a bien depose les noms CT 300h et CT 400h, mais
            // aucun n'a ete vendu.
            //   Le millesime 2017 est le dernier d'Amerique du Nord ; la
            //   generation court jusqu'en 2022 ailleurs, et c'est cette borne
            //   que porte le referentiel.
            ['lexus', 'ct-zwa10', 'CT 200h',   '200h',  1, $hybrid, $fwd, 134, 1798, 4, 'cvt',       '1,8 4 cylindres hybride 2ZR-FXE, 134 ch cumulés, traction'],

            // ── LEXUS RC XC10 (2014→) ────────────────────────────────────
            // Meme dedoublement que sur l'IS XE30, et pour la meme raison :
            // « RC 300 » designe le 2,0 turbo 8AR-FTS de 241 ch en propulsion
            // ET un 3,5 V6 de 260 ch en integrale, vendus cote a cote en
            // Amerique du Nord (Kelley Blue Book, millesimes 2023 et 2024). Les
            // deux noms du configurateur sont repris : « RC 300 » et
            // « RC 300 AWD ».
            //
            // Le catalogue de filtres pose DEUX des lignes de cette
            // generation : le 8AR-FTS 2016-2021 en propulsion (chassis ASC10,
            // tableau YZZA1, qui nomme « IS 300 / RC 300 / IS 200t ») et le
            // 2GR-FSE de la RC 350 propulsion 2015-2016 (chassis GSC10, YZZA3,
            // borne haute ramenee a 2016 par le document lui-meme). Il ne pose
            // RIEN pour le V6 d'apres 2016, ni en propulsion ni en integrale.
            //   Consequence mesuree : une « RC 300 AWD » de 2019 ne recoit que
            //   la YZZA1, celle du 2,0 turbo, faute d'une ligne pour son V6 —
            //   et une « RC 350 » du meme millesime, idem. Le document dit
            //   pourtant que le chassis GSC10 passe en YZZA1 a partir de
            //   11/2017, donc la cartouche rendue est peut-etre la bonne, mais
            //   elle est atteinte par la mauvaise ligne. A rapporter.
            //
            // La RC 300h est ECARTEE : hybride 2AR-FSE d'Europe et du Japon.
            //
            // Deux chiffres divergents, retenus comme suit : la RC 350 est a
            // 311 ch pour la fiche de la famille GR et a 314 pour celle du
            // modele — 311 retenu, c'est la valeur que la meme fiche GR donne a
            // l'IS 350 et a la GS 350, qui partagent ce moteur. La RC F est a
            // 467 ch jusqu'au restylage de 2019 et a 475 ensuite ; 467 est
            // retenu, c'est la tranche la plus longue.
            ['lexus', 'rc-xc10', 'RC 200t',    '200t',  1, $petrol, $rwd, 241, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 241 ch, propulsion'],
            ['lexus', 'rc-xc10', 'RC 300',     '300',   2, $petrol, $rwd, 241, 1998, 4, 'automatic', '2,0 4 cylindres turbo 8AR-FTS, 241 ch, propulsion — la RC 200t renommée'],
            ['lexus', 'rc-xc10', 'RC 300 AWD', '300X',  3, $petrol, $awd, 260, 3456, 6, 'automatic', '3,5 V6 2GR-FSE puis 2GR-FKS, 260 ch, intégrale — rien du 2,0 turbo que porte la RC 300 propulsion'],
            ['lexus', 'rc-xc10', 'RC 350',     '350',   4, $petrol, $rwd, 311, 3456, 6, 'automatic', '3,5 V6 2GR-FSE puis 2GR-FKS en 2018, 311 ch, propulsion ou intégrale'],
            ['lexus', 'rc-xc10', 'RC F',       'F',     5, $petrol, $rwd, 467, 4969, 8, 'automatic', '5,0 V8 2UR-GSE, 467 ch, propulsion'],
        ];

        $txMap = [
            'manual'    => 'manual',
            'automatic' => 'automatic',
            'cvt'       => 'cvt',
        ];

        foreach ($trims as $ligne) {
            [$brandSlug, $modelSlug, $name, $code, $rank, $engineId, $driveId, $hp, $cc, $cyl, $tx] = $ligne;

            // La douzieme colonne est arrivee avec le lot Lexus, et les 157
            // lignes anterieures n'en ont que onze. Le `?? null` est donc
            // indispensable : une destructuration a douze elements emettrait
            // « Undefined array key 11 » sur chacune de ces 157 lignes.
            //
            // Une description nulle est une description absente, pas une
            // description vide : la colonne est nullable et `TrimResource`
            // rend simplement `null`, comme avant ce lot.
            $description = $ligne[11] ?? null;

            $modelId = $resolve($brandSlug, $modelSlug);
            if (! $modelId) {
                continue;
            }

            // Évite les doublons sur (vehicle_model_id, name)
            $exists = DB::table('trims')
                ->where('vehicle_model_id', $modelId)
                ->where('name', $name)
                ->exists();

            if (! $exists) {
                DB::table('trims')->insert([
                    'vehicle_model_id'     => $modelId,
                    'name'                 => $name,
                    'code'                 => $code,
                    // Ce que « RX 350 » ne dit pas : la cylindree, le code
                    // moteur et la motricite. `TrimResource` l'expose deja,
                    // et elle valait null sur les 157 finitions d'avant.
                    'description'          => $description,
                    'rank'                 => $rank,
                    'default_engine_type_id' => $engineId,
                    'default_drivetrain_id'  => $driveId,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
        }
    }
}
