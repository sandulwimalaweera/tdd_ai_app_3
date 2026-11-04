<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class UserUuidTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_saving_a_user_generates_a_uuid_v7_primary_key(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUuid($user->id, version: 7));
        $this->assertModelExists($user);
    }

    public function test_separate_users_receive_different_uuid_primary_keys(): void
    {
        $users = User::factory()->count(2)->create();

        $this->assertTrue(Str::isUuid($users[0]->id, version: 7));
        $this->assertTrue(Str::isUuid($users[1]->id, version: 7));
        $this->assertNotSame($users[0]->id, $users[1]->id);
    }

    public function test_a_user_can_be_retrieved_by_its_uuid(): void
    {
        $id = '019a2f53-7c00-7000-8000-000000000001';
        User::factory()->create(['id' => $id]);

        $user = User::findOrFail($id);

        $this->assertSame($id, $user->id);
    }

    public function test_updating_a_user_preserves_its_uuid(): void
    {
        $id = '019a2f53-7c00-7000-8000-000000000001';
        $user = User::factory()->create(['id' => $id]);

        $user->update(['name' => 'Updated User']);

        $this->assertSame($id, $user->fresh()->id);
        $this->assertDatabaseHas('users', ['id' => $id, 'name' => 'Updated User']);
    }

    public function test_a_user_can_be_deleted_by_its_uuid(): void
    {
        $id = '019a2f53-7c00-7000-8000-000000000001';
        User::factory()->create(['id' => $id]);

        User::findOrFail($id)->delete();

        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    public function test_database_sessions_preserve_the_authenticated_users_uuid(): void
    {
        $user = User::factory()->create();
        config(['session.driver' => 'database', 'session.connection' => config('database.default')]);
        $this->actingAs($user, 'web');
        $session = $this->app['session']->driver();
        $session->start();
        $session->put('message', 'UUID session');

        $session->save();

        $this->assertTrue(Str::isUuid($user->id, version: 7));
        $this->assertDatabaseHas('sessions', [
            'id' => $session->getId(),
            'user_id' => $user->id,
        ]);
    }

    public function test_sanctum_tokens_resolve_their_uuid_user(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('UUID test');

        $storedToken = PersonalAccessToken::findToken($token->plainTextToken);
        $this->assertSame($user->id, $storedToken->tokenable_id);
        $this->assertTrue($storedToken->tokenable->is($user));
    }

    public function test_a_bearer_token_authenticates_a_user_with_a_uuid(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('UUID API test');

        $response = $this->withToken($token->plainTextToken)->getJson('/api/user');

        $response->assertOk()->assertJsonPath('id', $user->id);
        $this->assertTrue(Str::isUuid($response->json('id'), version: 7));
    }

    public function test_the_user_endpoint_returns_401_without_a_token(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertUnauthorized();
    }

    public function test_the_user_endpoint_returns_401_with_an_invalid_token(): void
    {
        $response = $this->withToken('invalid-token')->getJson('/api/user');

        $response->assertUnauthorized();
    }
}
