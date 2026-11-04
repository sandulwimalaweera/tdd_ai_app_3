<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->ensureUserReferencesAreEmpty('convert user primary keys to UUIDs');

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('id')->change();
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->nullable()->change();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('tokenable_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->ensureUserReferencesAreEmpty('revert UUID user primary keys');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('tokenable_id')->change();
        });

        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->bigIncrements('id')->change();
        });
    }

    private function ensureUserReferencesAreEmpty(string $operation): void
    {
        if (DB::table('users')->exists()
            || DB::table('personal_access_tokens')->exists()
            || DB::table('sessions')->whereNotNull('user_id')->exists()) {
            throw new RuntimeException(
                "Cannot {$operation} while users, personal access tokens, or authenticated sessions exist. "
                .'Migrate existing IDs and their references before retrying.'
            );
        }
    }
};
