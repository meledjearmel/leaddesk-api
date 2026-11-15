<?php

namespace Prototypes\Realtime;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Prototype 3 (phase 0) : connexion par jeton Sanctum et événement Reverb.
 * Code jetable, chargé seulement quand PROTOTYPES_ENABLED vaut true.
 */
class RealtimeController
{
    public function token(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Ces identifiants sont incorrects.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'channel' => PrototypePing::channelFor($user->id),
        ]);
    }

    public function ping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:200'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $sentAt = now()->toIso8601ZuluString('millisecond');

        PrototypePing::dispatch($user->id, $data['message'], $sentAt);

        return response()->json(['sent_at' => $sentAt]);
    }
}
