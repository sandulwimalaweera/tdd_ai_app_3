<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserUuidMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        try {
            DB::table('personal_access_tokens')->delete();
            DB::table('sessions')->delete();
            DB::table('users')->delete();
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_clean_install_creates_uuid_user_keys_and_preserves_indexes(): void
    {
        $this->assertUuidColumns();
        $this->assertFalse(Schema::getColumn('users', 'id')['auto_increment']);
        $this->assertTrue(Schema::getColumn('sessions', 'user_id')['nullable']);
        $this->assertTrue(Schema::hasIndex('users', ['id'], 'primary'));
        $this->assertTrue(Schema::hasIndex('sessions', ['user_id']));
        $this->assertTrue(Schema::hasIndex('personal_access_tokens', ['tokenable_type', 'tokenable_id']));
    }

    public function test_upgrading_an_empty_legacy_schema_preserves_guest_sessions(): void
    {
        $migration = $this->uuidMigration();
        $migration->down();
        DB::table('sessions')->insert([
            'id' => 'guest-session',
            'user_id' => null,
            'payload' => base64_encode('guest data'),
            'last_activity' => 1,
        ]);

        $migration->up();

        $this->assertUuidColumns();
        $this->assertDatabaseHas('sessions', [
            'id' => 'guest-session',
            'user_id' => null,
            'payload' => base64_encode('guest data'),
        ]);
    }

    #[DataProvider('userReferenceTables')]
    public function test_upgrading_populated_tables_stops_before_changing_columns(string $table): void
    {
        $migration = $this->uuidMigration();
        $migration->down();
        $this->insertUserReference($table, 1);
        $columns = $this->userKeyColumns();
        $exception = null;

        try {
            $migration->up();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception, 'The migration must refuse to convert existing user IDs without a backfill.');
        $this->assertStringContainsString('Cannot convert user primary keys to UUIDs', $exception->getMessage());
        $this->assertSame($columns, $this->userKeyColumns());
        $this->assertDatabaseCount($table, 1);
    }

    public function test_an_empty_schema_can_be_rolled_back_to_auto_incrementing_user_keys(): void
    {
        $migration = $this->uuidMigration();

        $migration->down();

        $this->assertSame('bigint', Schema::getColumnType('users', 'id'));
        $this->assertTrue(Schema::getColumn('users', 'id')['auto_increment']);
        $this->assertSame('bigint', Schema::getColumnType('sessions', 'user_id'));
        $this->assertTrue(Schema::getColumn('sessions', 'user_id')['nullable']);
        $this->assertSame('bigint', Schema::getColumnType('personal_access_tokens', 'tokenable_id'));
        $this->assertTrue(Schema::hasIndex('users', ['id'], 'primary'));

        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => 'unused-password',
        ]);

        $this->assertSame(1, $id);
    }

    #[DataProvider('userReferenceTables')]
    public function test_rolling_back_populated_tables_stops_before_changing_columns(string $table): void
    {
        $this->insertUserReference($table, '019a2f53-7c00-7000-8000-000000000001');
        $columns = $this->userKeyColumns();
        $exception = null;

        try {
            $this->uuidMigration()->down();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception, 'The migration must refuse to discard existing UUID user IDs.');
        $this->assertStringContainsString('Cannot revert UUID user primary keys', $exception->getMessage());
        $this->assertSame($columns, $this->userKeyColumns());
        $this->assertDatabaseCount($table, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function userReferenceTables(): array
    {
        return [
            'users' => ['users'],
            'authenticated sessions' => ['sessions'],
            'personal access tokens' => ['personal_access_tokens'],
        ];
    }

    private function uuidMigration(): Migration
    {
        return require database_path('migrations/2026_11_04_092628_convert_user_primary_keys_to_uuids.php');
    }

    private function assertUuidColumns(): void
    {
        $this->assertContains(Schema::getColumnType('users', 'id'), ['char', 'uuid']);
        $this->assertContains(Schema::getColumnType('sessions', 'user_id'), ['char', 'uuid']);
        $this->assertContains(Schema::getColumnType('personal_access_tokens', 'tokenable_id'), ['char', 'uuid']);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function userKeyColumns(): array
    {
        return [
            'users' => Schema::getColumns('users'),
            'sessions' => Schema::getColumns('sessions'),
            'personal_access_tokens' => Schema::getColumns('personal_access_tokens'),
        ];
    }

    private function insertUserReference(string $table, int|string $id): void
    {
        $attributes = match ($table) {
            'users' => [
                'id' => $id,
                'name' => 'Legacy User',
                'email' => 'legacy@example.com',
                'password' => 'unused-password',
            ],
            'sessions' => [
                'id' => 'authenticated-session',
                'user_id' => $id,
                'payload' => base64_encode('session data'),
                'last_activity' => 1,
            ],
            'personal_access_tokens' => [
                'tokenable_type' => User::class,
                'tokenable_id' => $id,
                'name' => 'Existing token',
                'token' => hash('sha256', 'test-token'),
            ],
        };

        DB::table($table)->insert($attributes);
    }
}
