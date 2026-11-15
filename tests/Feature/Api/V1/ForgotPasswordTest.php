<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mot de passe oublié depuis l'application (D-041).
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_known_address_receives_a_link_to_the_web_reset_page(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ceo@exemple.test']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ceo@exemple.test'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'y être envoyé.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password/'.$notification->token);
        });
    }

    /**
     * Même réponse pour une adresse inconnue : rien n'est dévoilé.
     */
    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@exemple.test'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'y être envoyé.');

        Notification::assertNothingSent();
    }

    public function test_the_address_is_validated(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pas une adresse'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_requests_are_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => "x{$i}@exemple.test"])->assertAccepted();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'x9@exemple.test'])->assertTooManyRequests();
    }
}
