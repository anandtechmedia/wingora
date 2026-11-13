<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSecurityFixtures;
use Tests\TestCase;

abstract class SecurityTestCase extends TestCase
{
    use BuildsSecurityFixtures, RefreshDatabase;

    /** Issues a real Sanctum token so tests exercise the production guard path. */
    protected function tokenFor(User $user, array $abilities = ['api:read', 'api:write']): string
    {
        return $user->createToken('test', $abilities, now()->addHour())->plainTextToken;
    }

    protected function apiAs(User $user, string $method, string $uri, array $data = [], array $abilities = ['api:read', 'api:write']): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($user, $abilities))->json($method, $uri, $data);
    }

    protected function apiWithToken(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
