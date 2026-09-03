<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Country;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    // Noms et prénoms communs en Afrique de l'Ouest francophone
    private array $firstNames = [
        'Abdoulaye', 'Adama', 'Aïssata', 'Aminata', 'Boubacar', 'Cheick',
        'Djibril', 'Fatoumata', 'Hamidou', 'Ibrahim', 'Issa', 'Kadiatou',
        'Mamadou', 'Mariam', 'Mohamed', 'Moussa', 'Nassim', 'Oumar',
        'Ramatou', 'Salif', 'Seydou', 'Souleymane', 'Tidiane', 'Yacouba',
        'Youssouf', 'Zenab', 'Arouna', 'Bintou', 'Coulibaly', 'Drissa',
        'Elhadj', 'Fanta', 'Gaoussou', 'Hawa', 'Issiaka', 'Juliette',
        'Kofi', 'Latif', 'Mamou', 'Nana', 'Ousmane', 'Pascal', 'Ramata',
    ];

    private array $lastNames = [
        'Ouédraogo', 'Traoré', 'Sawadogo', 'Diallo', 'Coulibaly', 'Koné',
        'Bah', 'Sow', 'Diop', 'Fall', 'Konaté', 'Touré', 'Sanogo',
        'Barry', 'Compaoré', 'Zongo', 'Tapsoba', 'Yameogo', 'Kaboré',
        'Kouyaté', 'Camara', 'Doumbia', 'Cissé', 'Keïta', 'Bagayoko',
        'Dembélé', 'Sylla', 'Baldé', 'Bamba', 'Ouattara', 'N\'Diaye',
        'Diabaté', 'Soro', 'Gnagne', 'Asséré', 'Adjobi', 'Koffi',
    ];

    // Indicatifs et patterns téléphoniques UEMOA
    private array $phonePatterns = [
        '+226 ## ## ## ##',  // Burkina Faso
        '+225 ## ## ## ## ##', // Côte d'Ivoire
        '+221 ## ### ## ##', // Sénégal
        '+223 ## ## ## ##',  // Mali
        '+227 ## ## ## ##',  // Niger
        '+228 ## ## ## ##',  // Togo
        '+229 ## ## ## ##',  // Bénin
    ];

    public function definition(): array
    {
        $type      = $this->faker->randomElement(['individual', 'individual', 'individual', 'company', 'ngo']);
        $isCompany = in_array($type, ['company', 'ngo']);

        $firstName = $isCompany ? null : $this->faker->randomElement($this->firstNames);
        $lastName  = $isCompany ? null : $this->faker->randomElement($this->lastNames);
        $company   = $isCompany ? $this->companyName() : null;

        $country = Country::where('iso2', 'BF')->first()
            ?? Country::inRandomOrder()->first();

        return [
            'code'                    => 'CLT-' . strtoupper(Str::random(6)),
            'type'                    => $type,
            'first_name'              => $firstName,
            'last_name'               => $lastName,
            'company_name'            => $company,
            'tax_id'                  => $isCompany ? $this->faker->numerify('IFU##########') : null,
            'id_document_type'        => $isCompany ? null : $this->faker->randomElement(['CNIB', 'passeport', 'carte_sejour']),
            'id_document_number'      => $isCompany ? null : $this->faker->numerify('B##########'),
            'driving_licence_number'  => $this->faker->optional(0.7)->numerify('BF################'),
            'driving_licence_expiry'  => $this->faker->optional(0.7)->dateTimeBetween('now', '+5 years')?->format('Y-m-d'),
            'phone'                   => $this->phone(),
            'phone_alt'               => $this->faker->optional(0.4)->passthrough($this->phone()),
            'email'                   => $this->faker->optional(0.6)->safeEmail(),
            'address'                 => $this->faker->optional(0.8)->streetAddress(),
            'city'                    => $this->faker->randomElement([
                'Ouagadougou', 'Bobo-Dioulasso', 'Koudougou', 'Banfora',
                'Ouahigouya', 'Abidjan', 'Dakar', 'Bamako',
            ]),
            'country_id'              => $country?->id,
            'is_blacklisted'          => $this->faker->boolean(3), // 3% blacklistés
            'notes'                   => null,
        ];
    }

    public function individual(): static
    {
        return $this->state(['type' => CustomerType::Individual->value, 'company_name' => null]);
    }

    public function company(): static
    {
        return $this->state(fn() => [
            'type'       => CustomerType::Company->value,
            'first_name' => null,
            'last_name'  => null,
            'company_name' => $this->companyName(),
        ]);
    }

    public function blacklisted(): static
    {
        return $this->state(['is_blacklisted' => true]);
    }

    private function phone(): string
    {
        $pattern = $this->faker->randomElement($this->phonePatterns);
        return $this->faker->numerify($pattern);
    }

    private function companyName(): string
    {
        $suffixes = ['SARL', 'SA', 'SAS', 'EURL', 'GIE', 'SCOOP'];
        $words    = ['Auto', 'Transport', 'Logistique', 'Commerce', 'Négoce', 'Import', 'Export', 'Distribution'];
        $names    = ['Ouédraogo', 'Traoré', 'Koné', 'Diallo', 'Burkina', 'Savane', 'Sahel', 'Volta'];

        return $this->faker->randomElement($words) . ' '
            . $this->faker->randomElement($names) . ' '
            . $this->faker->randomElement($suffixes);
    }
}
