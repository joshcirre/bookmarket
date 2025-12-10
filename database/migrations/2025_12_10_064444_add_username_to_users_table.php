<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('name');
            $table->text('bio')->nullable()->after('avatar');
        });

        // Generate usernames for existing users
        DB::table('users')->whereNull('username')->orderBy('id')->each(function ($user): void {
            $baseUsername = Str::slug($user->name) ?: 'user';
            $username = $baseUsername;
            $counter = 1;

            while (DB::table('users')->where('username', $username)->exists()) {
                $username = $baseUsername.'-'.$counter;
                $counter++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });

        // Now make the column required and unique
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['username', 'bio']);
        });
    }
};
