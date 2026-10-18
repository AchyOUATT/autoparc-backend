<?php

namespace App\Http\Requests;

/**
 * Recherche de pieces par criteres vehicule, sans vehicule enregistre.
 *
 * Les criteres etaient deja valides ; la pagination ne l'etait pas. La route
 * est publique et sert le catalogue de l'application : elle plafonne donc
 * per_page comme la route du garage, au meme seuil.
 *
 * Elle n'herite pas des filtres de CompatiblePartsRequest : le moteur ne lit ici
 * que les criteres ci-dessous, et valider un parametre que la route ignore
 * laisserait croire qu'il filtre.
 *
 * ── LE CODE MOTEUR, AJOUTE APRES COUP ──────────────────────────────────────
 *
 * Il manquait, et son absence rendait la recherche ambigue sur les modeles qui
 * ont porte plusieurs moteurs. Le catalogue de correspondance des filtres a
 * huile ouvre la-dessus : « Le filtre depend du code moteur, pas du nom du
 * modele : une meme Camry peut prendre trois filtres differents selon l'annee
 * et la motorisation. »
 *
 * Mesure avant ce changement, Camry XV70 de 2019 : la route rendait LES DEUX
 * filtres — la cartouche 04152-YZZA1 du 2GR-FKS et le visse 90915-YZZN1 de
 * l'A25A — en laissant au vendeur le soin de lire le code moteur sous le
 * capot. Le service savait pourtant filtrer dessus ; c'est le controleur qui
 * ne lui passait rien.
 *
 * IL RESTE FACULTATIF, et ce n'est pas une precaution de style : les versions
 * de l'application deja installees ne l'envoient pas, et elles continueront
 * pendant des mois. Sans lui, le comportement est exactement celui d'avant.
 */
class CompatiblePartsCriteriaRequest extends CompatiblePartsRequest
{
    public function rules(): array
    {
        return $this->paginationRules() + [
            'vehicle_model_id' => ['required', 'exists:vehicle_models,id'],
            'year'             => ['nullable', 'integer', 'min:1950'],
            'trim_id'          => ['nullable', 'exists:trims,id'],
            'engine_type_id'   => ['nullable', 'exists:engine_types,id'],
            'drivetrain_id'    => ['nullable', 'exists:drivetrains,id'],

            // Pas de `exists:` : un code moteur n'est pas une clef etrangere,
            // c'est une chaine libre sur part_fitments. Un code inconnu ne doit
            // pas rendre 422 — il rend les compatibilites sans code moteur,
            // celles qui valent pour tout le modele, ce qui est la bonne
            // reponse a « je ne reconnais pas ce moteur ».
            'engine_code'      => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Le code moteur est compare par EGALITE EXACTE en base.
     *
     * `CompatibilityService` fait `where('engine_code', $code)`, et le
     * referentiel les stocke en majuscules avec tiret — « 2AR-FE »,
     * « A25A-FKS ». Un vendeur qui tape « 2ar-fe » ne trouverait donc que les
     * compatibilites sans code moteur, et conclurait a tort qu'il n'y a rien.
     *
     * On normalise ici plutot que dans le service : la route du garage lui
     * passe `effective_engine_code`, qui vient de la base et est deja
     * canonique. C'est l'entree humaine qu'il faut redresser, pas la sortie
     * machine.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('engine_code')) {
            return;
        }

        $brut = trim((string) $this->input('engine_code'));

        $this->merge([
            'engine_code' => $brut === '' ? null : mb_strtoupper($brut),
        ]);
    }

    public function messages(): array
    {
        return $this->paginationMessages() + [
            'vehicle_model_id.required' => 'Sans modèle de véhicule, la recherche de compatibilité n\'a pas de sens.',
            'vehicle_model_id.exists'   => "Ce modèle de véhicule n'existe pas.",
            'year.min'                  => 'Aucun véhicule du catalogue n\'est antérieur à :min.',
            'trim_id.exists'            => "Cette finition n'existe pas.",
            'engine_type_id.exists'     => "Cette motorisation n'existe pas.",
            'drivetrain_id.exists'      => "Cette transmission n'existe pas.",
        ];
    }
}
