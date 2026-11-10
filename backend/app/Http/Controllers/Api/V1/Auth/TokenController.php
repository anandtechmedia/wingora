<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Security\LoginService;
use App\Security\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Expiring, revocable bearer tokens for mobile apps and integrations. */
class TokenController extends Controller
{
    public function __construct(private readonly LoginService $logins, private readonly TwoFactor $mfa) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(config('security.tokens.abilities'))],
            'mfa_code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $this->logins->attempt($data['email'], $data['password']);

        abort_if($user->requiresMfa() && ! $user->hasMfaEnabled(), 403, 'Your role requires MFA. Enrol from the web app before creating API tokens.');

        if ($user->hasMfaEnabled() && ! $this->mfa->verify($user, $data['mfa_code'] ?? null, $data['recovery_code'] ?? null)) {
            SecurityEvent::record('auth.mfa_failed', 'warning', $user, ['channel' => 'token']);
            throw ValidationException::withMessages(['mfa_code' => 'A valid authentication code is required.']);
        }

        $max = (int) config('security.tokens.max_per_user');
        $excess = $user->tokens()->count() - $max + 1;
        if ($excess > 0) {
            $user->tokens()->oldest('id')->limit($excess)->get()->each->delete();
        }

        $expiresAt = now()->addMinutes((int) config('security.tokens.ttl_minutes'));
        $token = $user->createToken(strip_tags($data['device_name']), $data['abilities'] ?? ['api:read'], $expiresAt);

        $this->logins->recordSuccess($user, $request, 'token');
        SecurityEvent::record('auth.token_issued', 'info', $user, ['token_id' => $token->accessToken->id, 'abilities' => $token->accessToken->abilities]);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        return response()->json(['data' => $request->user()->tokens()->latest('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'current' => $current && $current->getKey() === $t->id,
        ])]);
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        // Scoped to the caller's own tokens: other users' token ids return 404.
        $request->user()->tokens()->whereKey($tokenId)->firstOrFail()->delete();
        SecurityEvent::record('auth.token_revoked', 'info', $request->user(), ['token_id' => $tokenId]);

        return response()->json(null, 204);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token && method_exists($token, 'delete') && $token->getKey(), 400, 'No bearer token in use.');
        $token->delete();
        SecurityEvent::record('auth.token_revoked', 'info', $request->user(), ['token_id' => $token->getKey()]);

        return response()->json(null, 204);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $count = $request->user()->tokens()->delete();
        SecurityEvent::record('auth.tokens_revoked_all', 'warning', $request->user(), ['count' => $count]);

        return response()->json(null, 204);
    }
}
