<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ou atterrissent les photos du catalogue.
 *
 * Le controleur ecrivait sur le disque `public`, fige dans une constante. En
 * developpement c'est le bon choix ; en ligne, c'est une perte annoncee. Le
 * disque de Render est ephemere : les fichiers disparaissent au deploiement
 * suivant — et une migration de donnees en declenche un — en laissant des
 * lignes `media` qui pointent sur des fichiers absents. Le commercant
 * chargerait ses photos et les verrait s'evaporer sans qu'aucun message ne le
 * previenne.
 *
 * Deux choses se defendent mal toutes seules et sont verrouillees ici.
 *
 * *Le disque se configure.* Sans cela, impossible de basculer la production
 * sur un stockage objet sans toucher au code.
 *
 * *Les photos deja en place gardent le leur.* La colonne `disk` est enregistree
 * ligne par ligne : une bascule de configuration ne doit pas rendre illisibles
 * les photos ecrites avant elle, ni les faire chercher au mauvais endroit a la
 * suppression. C'est le piege classique d'une migration de stockage.
 */
class StockagePhotosTest extends TestCase
{
    use RefreshDatabase;

    private Part $piece;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->piece = Part::factory()->create();
    }

    private function envoyer(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/parts/{$this->piece->id}/media", [
            // `->image()` genererait une vraie image et exigerait l'extension
            // GD, absente de certains postes. Un fichier type suffit : la
            // validation regarde l'extension et le type MIME.
            'photos' => [UploadedFile::fake()->create('filtre.jpg', 120, 'image/jpeg')],
        ]);
    }

    public function test_la_photo_atterrit_sur_le_disque_configure(): void
    {
        Storage::fake('s3');
        config(['media.disk' => 's3']);

        $this->envoyer()->assertCreated();

        $media = Media::firstOrFail();

        $this->assertSame('s3', $media->disk);
        Storage::disk('s3')->assertExists($media->path);
    }

    public function test_le_defaut_reste_le_disque_local(): void
    {
        Storage::fake('public');

        $this->assertSame('public', config('media.disk'));

        $this->envoyer()->assertCreated();

        $this->assertSame('public', Media::firstOrFail()->disk);
    }

    /**
     * Le piege d'une bascule de stockage.
     *
     * Une photo ecrite avant le changement vit toujours sur l'ancien disque.
     * Si la suppression lisait la configuration plutot que la ligne, elle
     * chercherait au mauvais endroit : le fichier resterait sur l'ancien
     * stockage, et la ligne disparaitrait de la base — une fuite silencieuse.
     */
    public function test_une_photo_ancienne_est_supprimee_de_son_propre_disque(): void
    {
        Storage::fake('public');
        Storage::fake('s3');

        // Chargee du temps du disque local.
        $this->envoyer()->assertCreated();
        $ancienne = Media::firstOrFail();
        $this->assertSame('public', $ancienne->disk);

        // Puis la production bascule sur le stockage objet.
        config(['media.disk' => 's3']);

        $this->deleteJson("/api/media/{$ancienne->id}")->assertOk();

        Storage::disk('public')->assertMissing($ancienne->path);
        $this->assertDatabaseMissing('media', ['id' => $ancienne->id]);
    }

    /** Et les nouvelles partent bien sur le nouveau disque, en meme temps. */
    public function test_les_deux_disques_cohabitent(): void
    {
        Storage::fake('public');
        Storage::fake('s3');

        $this->envoyer()->assertCreated();

        config(['media.disk' => 's3']);

        $this->envoyer()->assertCreated();

        $disques = Media::orderBy('id')->pluck('disk')->all();

        $this->assertSame(['public', 's3'], $disques);
    }

    /**
     * Un disque qui refuse l'ecriture ne doit rien laisser en base.
     *
     * Ce test existe a cause d'une panne reelle, constatee le 2026-10-16. Le
     * stockage R2 refusait les ecritures ; le disque s3 etant configure avec
     * `throw => false` et `report => false`, rien ne remontait : ni exception,
     * ni ligne de journal. `storeAs()` rendait `false`, le controleur l'ecrivait
     * tel quel, et la base enregistrait `path = 0`. L'API construisait alors
     * « <AWS_URL>/0 » — un 404 — tout en renvoyant un 201 que l'application
     * affichait en « photo ajoutee ».
     *
     * Deux lignes fantomes ont ete creees ainsi, et le chemin heureux teste
     * plus haut n'a rien pu signaler : il ne voit jamais un disque qui echoue.
     * C'est l'absence de ce test-ci qui a laisse la panne passer pour un succes.
     *
     * On verifie les deux moities du contrat : l'appelant est averti, et la
     * base reste propre.
     */
    public function test_un_disque_qui_refuse_l_ecriture_ne_cree_aucune_ligne(): void
    {
        config(['media.disk' => 's3']);

        // putFileAs rend `false` quand l'ecriture echoue, et UploadedFile::storeAs
        // propage ce `false` sans rien dire. C'est exactement ce que faisait R2.
        $disque = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disque->shouldReceive('putFileAs')->once()->andReturn(false);

        // `url()` doit repondre, sinon le test ne vaut rien. Sans elle, retirer
        // le garde-fou fait echouer formatMedia() sur un appel inconnu du faux
        // disque, et le test tombe sur un 500 — une erreur de montage, pas la
        // panne. En production `url()` existe et rend « <AWS_URL>/0 » : la
        // requete aboutit en 201 et c'est precisement ce qu'on veut interdire.
        // Verifie en neutralisant le garde-fou : le test doit alors echouer sur
        // le 201 et sur la ligne creee, pas sur une methode manquante.
        $disque->shouldReceive('url')->andReturn('https://exemple.test/0');

        Storage::shouldReceive('disk')->andReturn($disque);

        $this->envoyer()
            ->assertStatus(502)
            ->assertJsonPath('data', []);

        $this->assertDatabaseCount('media', 0);
    }
}
