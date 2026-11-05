<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignInTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_credentials_return_a_sanctum_token_and_safe_uuid_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Example User',
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/sign-in', ['email' => 'user@example.com', 'password' => 'correct-password']);

        $response->assertOk()->assertExactJson([
            'data' => [
                'access_token' => $response->json('data.access_token'),
                'token_type' => 'Bearer',
                'user' => ['id' => $user->id, 'name' => 'Example User', 'email' => 'user@example.com'],
            ],
        ]);
        $accessToken = $response->json('data.access_token');
        $this->assertIsString($accessToken);
        $storedToken = PersonalAccessToken::findToken($accessToken);
        $this->assertNotNull($storedToken);
        $this->assertSame($user->id, $storedToken->tokenable_id);
        $this->assertTrue($storedToken->tokenable->is($user));
        [, $secret] = explode('|', $accessToken, 2);
        $this->assertSame(hash('sha256', $secret), $storedToken->token);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertGuest('web');
    }

    public function test_the_returned_token_authenticates_a_request_to_the_user_endpoint(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $signIn = $this->postJson('/api/sign-in', ['email' => $user->email, 'password' => 'correct-password']);
        $signIn->assertOk();

        $response = $this->withToken($signIn->json('data.access_token'))->getJson('/api/user');

        $response->assertOk()->assertJsonPath('id', $user->id);
    }

    #[DataProvider('incorrectCredentials')]
    public function test_incorrect_credentials_return_the_same_401_response_without_issuing_a_token(string $email, string $password): void
    {
        User::factory()->create(['email' => 'user@example.com', 'password' => 'correct-password']);

        $response = $this->postJson('/api/sign-in', ['email' => $email, 'password' => $password]);

        $response->assertUnauthorized()->assertExactJson(['message' => 'The provided credentials are incorrect.']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest('web');
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_return_422_without_creating_users_or_tokens(array $payload, array $errors): void
    {
        $response = $this->postJson('/api/sign-in', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors(array_keys($errors));
        foreach ($errors as $field => $message) {
            $response->assertJsonPath("errors.{$field}.0", $message);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('validPasswords')]
    public function test_existing_passwords_are_verified_exactly_without_registration_rules(string $password): void
    {
        $user = User::factory()->create(['password' => $password]);

        $response = $this->postJson('/api/sign-in', ['email' => $user->email, 'password' => $password]);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_accepts_an_email_at_the_maximum_supported_length(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 58).'.com';
        User::factory()->create(['email' => $email, 'password' => 'correct-password']);

        $response = $this->postJson('/api/sign-in', ['email' => $email, 'password' => 'correct-password']);

        $response->assertOk()->assertJsonPath('data.user.email', $email);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_each_successful_sign_in_issues_a_new_token_and_preserves_earlier_tokens(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $credentials = ['email' => $user->email, 'password' => 'correct-password'];
        $firstSignIn = $this->postJson('/api/sign-in', $credentials);
        $firstSignIn->assertOk();

        $secondSignIn = $this->postJson('/api/sign-in', $credentials);

        $secondSignIn->assertOk();
        $firstToken = $firstSignIn->json('data.access_token');
        $secondToken = $secondSignIn->json('data.access_token');
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertNotNull(PersonalAccessToken::findToken($firstToken));
        $this->assertNotNull(PersonalAccessToken::findToken($secondToken));
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    #[DataProvider('equivalentEmails')]
    public function test_a_sixth_attempt_returns_429_and_issues_no_token_even_with_valid_credentials(string $email): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'correct-password']);
        $this->failSignInFiveTimes($user->email);

        $response = $this->postJson('/api/sign-in', ['email' => $email, 'password' => 'correct-password']);

        $response->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_sign_in_limits_are_separate_for_different_ip_addresses(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'correct-password']);
        $this->failSignInFiveTimes($user->email);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/sign-in', ['email' => $user->email, 'password' => 'correct-password']);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_sign_in_limits_are_separate_for_different_email_addresses(): void
    {
        $this->freezeTime();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create(['password' => 'correct-password']);
        $this->failSignInFiveTimes($firstUser->email);

        $response = $this->postJson('/api/sign-in', ['email' => $secondUser->email, 'password' => 'correct-password']);

        $response->assertOk()->assertJsonPath('data.user.id', $secondUser->id);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_sign_in_is_allowed_again_after_the_rate_limit_expires(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'correct-password']);
        $this->failSignInFiveTimes($user->email);
        $this->travel(61)->seconds();

        $response = $this->postJson('/api/sign-in', ['email' => $user->email, 'password' => 'correct-password']);

        $response->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function equivalentEmails(): array
    {
        return [
            'same email' => ['user@example.com'],
            'different casing' => ['USER@EXAMPLE.COM'],
            'surrounding whitespace' => [' user@example.com '],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function incorrectCredentials(): array
    {
        return [
            'unknown email' => ['missing@example.com', 'correct-password'],
            'incorrect password' => ['user@example.com', 'incorrect-password'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing credentials' => [[], ['email' => 'The email field is required.', 'password' => 'The password field is required.']],
            'blank email' => [['email' => '   ', 'password' => 'password'], ['email' => 'The email field is required.']],
            'non-string email' => [['email' => ['user@example.com'], 'password' => 'password'], ['email' => 'The email field must be a string.']],
            'invalid email' => [['email' => 'not-an-email', 'password' => 'password'], ['email' => 'The email field must be a valid email address.']],
            'long email' => [
                ['email' => str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 59).'.com', 'password' => 'password'],
                ['email' => 'The email field must not be greater than 191 characters.'],
            ],
            'blank password' => [['email' => 'user@example.com', 'password' => ''], ['password' => 'The password field is required.']],
            'null password' => [['email' => 'user@example.com', 'password' => null], ['password' => 'The password field is required.']],
            'non-string password' => [['email' => 'user@example.com', 'password' => ['password']], ['password' => 'The password field must be a string.']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validPasswords(): array
    {
        return [
            'leading and trailing whitespace' => ['  exact-password  '],
            'existing short password' => ['x'],
        ];
    }

    private function failSignInFiveTimes(string $email): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/sign-in', ['email' => $email, 'password' => 'incorrect-password'])->assertUnauthorized();
        }
    }
}
