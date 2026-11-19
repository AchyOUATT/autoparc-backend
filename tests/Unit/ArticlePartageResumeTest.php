<?php

namespace Tests\Unit;

use App\Support\ArticlePartage;
use Tests\TestCase;

/**
 * Ce que l'apercu de partage rend comme texte, selon le type d'article.
 */
class ArticlePartageResumeTest extends TestCase
{
    public function test_la_compatibilite_passe_avant_la_description(): void
    {
        $article = $this->article(
            description: 'Visse, ancienne reference remplacee par le 90915-YZZN1.',
            compatibilite: 'Compatible : Toyota Corolla E100 (1991-1995, 4A-FE)',
            descriptionDansLApercu: false,
        );

        $this->assertSame(
            'Compatible : Toyota Corolla E100 (1991-1995, 4A-FE)',
            $article->resume(),
        );
    }

    public function test_sans_compatibilite_une_piece_n_affiche_pas_sa_description_dans_l_apercu(): void
    {
        $article = $this->article(
            description: 'Visse, ancienne reference remplacee par le 90915-YZZN1.',
            descriptionDansLApercu: false,
            precisions: [
                'Catégorie' => 'Filtre à huile',
                'Référence' => '90915-10001',
                'État' => 'Neuve',
            ],
        );

        $this->assertSame('Filtre à huile · 90915-10001 · Neuve', $article->resume());
        $this->assertStringNotContainsString('Visse', $article->resume());
        $this->assertSame(
            'Visse, ancienne reference remplacee par le 90915-YZZN1.',
            $article->description,
        );
    }

    public function test_un_vehicule_garde_sa_description_dans_l_apercu(): void
    {
        $article = $this->article(
            titre: 'Mazda CX-9 (2018)',
            description: 'Un seul propriétaire, carnet complet.',
        );

        $this->assertSame('Un seul propriétaire, carnet complet.', $article->resume());
    }

    public function test_sans_description_ni_compatibilite_les_precisions_servent_de_repli(): void
    {
        $article = $this->article(
            description: '   ',
            precisions: ['Catégorie' => 'Filtre à huile', 'État' => 'Neuve'],
        );

        $this->assertSame('Filtre à huile · Neuve', $article->resume());
    }

    /**
     * @param  array<string, string|null>  $precisions
     */
    private function article(
        string $titre = 'Filtre a huile visse 90915-10001',
        ?string $description = null,
        ?string $compatibilite = null,
        bool $descriptionDansLApercu = true,
        array $precisions = [],
    ): ArticlePartage {
        return new ArticlePartage(
            titre: $titre,
            prix: 3000.0,
            description: $description,
            photo: null,
            precisions: $precisions,
            compatibilite: $compatibilite,
            descriptionDansLApercu: $descriptionDansLApercu,
        );
    }
}
