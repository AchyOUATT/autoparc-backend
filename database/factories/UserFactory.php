<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            // CETTE LIGNE N'EST PAS DECORATIVE.
            //
            // La colonne a `default(true)` en base, mais une valeur par defaut
            // SQL ne remonte pas dans le modele en memoire : `create()` ne
            // relit pas la ligne ecrite. Sans cette ligne, l'attribut est donc
            // ABSENT de l'objet rendu, et le cast `boolean` lit `null` comme
            // faux.
            //
            // Les tests qui passent ce modele a `actingAs()` donnent alors a la
            // garde un utilisateur qui se lit comme DESACTIVE. Au moment ou
            // `EnsureActiveUser` et `EnsureStaff` ont commence a controler
            // `is_active`, 119 tests du personnel sont tombes en 403 d'un coup
            // pour cette seule raison.
            //
            // En production le probleme ne se pose pas : Sanctum resout le
            // jeton contre la base, donc la colonne est toujours la. Les
            // middlewares refusent volontairement un utilisateur dont
            // `is_active` est inconnu — c'est la fabrique qui devait dire la
            // verite, pas eux qui devaient se relacher.
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
