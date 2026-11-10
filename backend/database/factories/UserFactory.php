<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Correct-Horse-9!'),
            'remember_token' => Str::random(10),
        ];
    }
}
