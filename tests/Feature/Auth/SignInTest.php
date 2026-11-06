<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

pest()->use(LazilyRefreshDatabase::class);

$rejectFiveAttempts = static function (TestCase $testCase, string $email): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $testCase->postJson('/api/sign-in', ['email' => $email, 'password' => 'incorrect-password'])->assertUnauthorized();
    }
};

test('valid sign-in returns a token and user', function (): void {
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
});

test('returned token authenticates the user', function (): void {
    $user = User::factory()->create(['password' => 'correct-password']);
    $signIn = $this->postJson('/api/sign-in', ['email' => $user->email, 'password' => 'correct-password']);
    $signIn->assertOk();

    $response = $this->withToken($signIn->json('data.access_token'))->getJson('/api/user');

    $response->assertOk()->assertJsonPath('id', $user->id);
});

test('invalid credentials return 401', function (string $email, string $password): void {
    User::factory()->create(['email' => 'user@example.com', 'password' => 'correct-password']);

    $response = $this->postJson('/api/sign-in', ['email' => $email, 'password' => $password]);

    $response->assertUnauthorized()->assertExactJson(['message' => 'The provided credentials are incorrect.']);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('users', 1);
    $this->assertGuest('web');
})->with('sign-in incorrect credentials');

test('invalid input returns 422', function (array $payload, array $errors): void {
    $response = $this->postJson('/api/sign-in', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors(array_keys($errors));
    foreach ($errors as $field => $message) {
        $response->assertJsonPath("errors.{$field}.0", $message);
    }
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('users', 0);
})->with('sign-in invalid payloads');

test('passwords are verified exactly', function (string $password): void {
    $user = User::factory()->create(['password' => $password]);

    $response = $this->postJson('/api/sign-in', ['email' => $user->email, 'password' => $password]);

    $response->assertOk()->assertJsonPath('data.user.id', $user->id);
    $this->assertDatabaseCount('personal_access_tokens', 1);
})->with('sign-in valid passwords');

test('accepts a 191-character email', function (): void {
    $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 58).'.com';
    User::factory()->create(['email' => $email, 'password' => 'correct-password']);

    $response = $this->postJson('/api/sign-in', ['email' => $email, 'password' => 'correct-password']);

    $response->assertOk()->assertJsonPath('data.user.email', $email);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('sign-in preserves earlier tokens', function (): void {
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
});

test('sixth attempt returns 429', function (string $email) use ($rejectFiveAttempts): void {
    $this->freezeTime();
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'correct-password']);
    $rejectFiveAttempts($this, $user->email);

    $response = $this->postJson('/api/sign-in', ['email' => $email, 'password' => 'correct-password']);

    $response->assertTooManyRequests()->assertHeader('Retry-After', '60');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with('sign-in equivalent emails');

test('limits are separate for each IP', function () use ($rejectFiveAttempts): void {
    $this->freezeTime();
    $user = User::factory()->create(['password' => 'correct-password']);
    $rejectFiveAttempts($this, $user->email);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson('/api/sign-in', ['email' => $user->email, 'password' => 'correct-password']);

    $response->assertOk()->assertJsonPath('data.user.id', $user->id);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('limits are separate for each email', function () use ($rejectFiveAttempts): void {
    $this->freezeTime();
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create(['password' => 'correct-password']);
    $rejectFiveAttempts($this, $firstUser->email);

    $response = $this->postJson('/api/sign-in', ['email' => $secondUser->email, 'password' => 'correct-password']);

    $response->assertOk()->assertJsonPath('data.user.id', $secondUser->id);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('sign-in resumes after the limit expires', function () use ($rejectFiveAttempts): void {
    $this->freezeTime();
    $user = User::factory()->create(['password' => 'correct-password']);
    $rejectFiveAttempts($this, $user->email);
    $this->travel(61)->seconds();

    $response = $this->postJson('/api/sign-in', ['email' => $user->email, 'password' => 'correct-password']);

    $response->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

dataset('sign-in equivalent emails', [
    'same email' => ['user@example.com'],
    'different casing' => ['USER@EXAMPLE.COM'],
    'surrounding whitespace' => [' user@example.com '],
]);

dataset('sign-in incorrect credentials', [
    'unknown email' => ['missing@example.com', 'correct-password'],
    'incorrect password' => ['user@example.com', 'incorrect-password'],
]);

dataset('sign-in invalid payloads', [
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
]);

dataset('sign-in valid passwords', [
    'leading and trailing whitespace' => ['  exact-password  '],
    'existing short password' => ['x'],
]);
