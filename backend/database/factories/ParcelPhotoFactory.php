<?php

namespace Database\Factories;

use App\Models\Parcel;
use App\Models\ParcelPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParcelPhotoFactory extends Factory
{
    protected $model = ParcelPhoto::class;

    public function definition(): array
    {
        return [
            'parcel_id' => Parcel::factory(),
            'type'      => 'pickup',
            'photo_url' => 'https://storage.golivreur.ci/parcels/test.jpg',
            'lat'       => 6.8770,
            'lng'       => -6.4502,
        ];
    }
}
