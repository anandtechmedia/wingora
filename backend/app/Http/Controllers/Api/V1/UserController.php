<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\LoginService;
use App\Security\RoleAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return UserResource::collection(User::query()->with('roles')->orderBy('name')->paginate((int) $request->input('per_page', 25)));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    /** Invites a user: a random password is set and a reset link is e-mailed. */
    public function store(Request $request, RoleAssigner $assigner): JsonResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1', 'max:9'],
            'roles.*' => ['string', 'distinct', 'max:60'],
        ]);

        $user = DB::transaction(function () use ($data, $request, $assigner) {
            $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => Str::password(40)]);
            $assigner->assign($request->user(), $user, $data['roles']);

            return $user;
        });

        Password::sendResetLink(['email' => $user->email]);

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function updateRoles(Request $request, User $user, RoleAssigner $assigner): UserResource
    {
        Gate::authorize('assignRoles', $user);
        $data = $request->validate(['roles' => ['present', 'array', 'max:9'], 'roles.*' => ['string', 'distinct', 'max:60']]);

        $assigner->assign($request->user(), $user, $data['roles']);

        return new UserResource($user->load('roles'));
    }

    public function updateStatus(Request $request, User $user, LoginService $logins): UserResource
    {
        Gate::authorize('update', $user);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);

        $user->forceFill(['status' => $data['status']])->save();
        if ($data['status'] === 'disabled') {
            $logins->revokeEverything($user);
        }
        SecurityEvent::record('user.status_changed', 'warning', $request->user(), ['target' => $user->public_id, 'status' => $data['status']]);

        return new UserResource($user->load('roles'));
    }
}
