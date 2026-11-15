<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Comptes et jetons de l'application de bureau.
 */
class AuthController extends ApiController
{
    public function register(Request $request, CreateNewUser $createUser): JsonResponse
    {
        $device = $request->validate(['device_name' => ['required', 'string', 'max:100']]);

        /** @var array<string, string> $input */
        $input = $request->only(['name', 'email', 'password', 'password_confirmation']);
        $user = $createUser->create($input);

        return $this->tokenResponse($user, $device['device_name'], Response::HTTP_CREATED);
    }

    public function token(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->tokenResponse($user, $credentials['device_name']);
    }

    public function show(Request $request): UserResource
    {
        return new UserResource($this->user($request)->load('company'));
    }

    /**
     * Révoque le jeton de l'appareil qui fait la demande.
     */
    public function destroy(Request $request): Response
    {
        $this->user($request)->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function tokenResponse(User $user, string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user->load('company')),
        ], $status);
    }
}
