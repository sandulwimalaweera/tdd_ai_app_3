<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignInRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SignInController extends Controller
{
    public function __invoke(SignInRequest $request): JsonResponse
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        if (! $guard->validate($request->validated())) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();
        $token = $user->createToken('sign-in');

        return response()->json([
            'data' => [
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ],
        ]);
    }
}
