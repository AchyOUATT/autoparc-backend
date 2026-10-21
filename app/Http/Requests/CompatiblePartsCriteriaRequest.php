<?php

namespace App\Http\Requests;

use App\Support\EngineCode;
use Illuminate\Validation\Rule;

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
 * Le code reste facultatif : les déclarations universelles peuvent être
 * confirmées sans lui. Les références propres à un moteur restent disponibles
 * avec include_candidates=1, sans être confondues avec les confirmations.
 */
class CompatiblePartsCriteriaRequest extends CompatiblePartsRequest
{
    public function rules(): array
    {
        return $this->paginationRules() + [
            'vehicle_model_id' => ['required', 'integer', 'exists:vehicle_models,id'],
            'year'             => ['nullable', 'integer', 'min:1950'],
            'trim_id'          => ['nullable', 'integer', Rule::exists('trims', 'id')->where(
                'vehicle_model_id', is_scalar($this->input('vehicle_model_id')) ? $this->input('vehicle_model_id') : null,
            )],
            'engine_type_id'   => ['nullable', 'integer', 'exists:engine_types,id'],
            'drivetrain_id'    => ['nullable', 'integer', 'exists:drivetrains,id'],

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
     * La même normalisation est partagée avec les écritures du garage et du
     * parc. Le service normalise aussi les anciennes valeurs déjà stockées.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('engine_code')) {
            return;
        }

        $brut = $this->input('engine_code');
        if ($brut !== null && ! is_string($brut)) {
            return;
        }

        $this->merge([
            'engine_code' => EngineCode::normalize($brut),
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
