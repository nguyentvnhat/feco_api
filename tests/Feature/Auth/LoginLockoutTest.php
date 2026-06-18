<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoginLockoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->prepareSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_three_wrong_attempts_lock_account(): void
    {
        $userId = $this->seedUser('0901000001', 'test1@example.com', 'correct-password');

        $this->postJson('/api/v1/auth/login', [
            'login' => '0901000001',
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Thông tin đăng nhập không đúng.');

        $this->postJson('/api/v1/auth/login', [
            'login' => '0901000001',
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Thông tin đăng nhập không đúng.');

        $this->postJson('/api/v1/auth/login', [
            'login' => '0901000001',
            'password' => 'wrong-password',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên để mở lại.');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'status' => 'locked',
            'failed_login_attempts' => 3,
            'locked_reason' => 'Đăng nhập sai quá 3 lần',
        ]);
        $this->assertNotNull(DB::table('users')->where('id', $userId)->value('locked_at'));
    }

    public function test_locked_account_cannot_login_even_with_correct_password(): void
    {
        $this->seedUser(
            '0901000002',
            'test2@example.com',
            'correct-password',
            status: 'locked',
            failedAttempts: 3,
            lockedAt: now()->toDateTimeString(),
            lockedReason: 'Đăng nhập sai quá 3 lần'
        );

        $this->postJson('/api/v1/auth/login', [
            'login' => '0901000002',
            'password' => 'correct-password',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Tài khoản đã bị khóa. Vui lòng liên hệ quản trị viên để mở lại.');
    }

    public function test_successful_login_resets_failed_attempts(): void
    {
        $userId = $this->seedUser(
            '0901000003',
            'test3@example.com',
            'correct-password',
            failedAttempts: 2,
            lastFailedAt: now()->subMinute()->toDateTimeString()
        );

        $this->postJson('/api/v1/auth/login', [
            'login' => '0901000003',
            'password' => 'correct-password',
        ])->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'refresh_token']]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'failed_login_attempts' => 0,
        ]);
        $this->assertNull(DB::table('users')->where('id', $userId)->value('last_failed_login_at'));
    }

    public function test_admin_unlock_allows_login_again(): void
    {
        $userId = $this->seedUser(
            '0901000004',
            'test4@example.com',
            'correct-password',
            status: 'locked',
            failedAttempts: 3,
            lastFailedAt: now()->subMinute()->toDateTimeString(),
            lockedAt: now()->subMinute()->toDateTimeString(),
            lockedReason: 'Đăng nhập sai quá 3 lần'
        );

        // Simulate admin unlock action payload.
        DB::table('users')->where('id', $userId)->update([
            'status' => 'active',
            'failed_login_attempts' => 0,
            'last_failed_login_at' => null,
            'locked_at' => null,
            'locked_reason' => null,
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '0901000004',
            'password' => 'correct-password',
        ])->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'refresh_token']]);
    }

    private function prepareSchema(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100)->nullable();
            $table->string('email', 150)->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password');
            $table->string('status', 20)->default('active');
            $table->dateTime('last_login_at')->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason', 255)->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedUser(
        string $phone,
        string $email,
        string $password,
        string $status = 'active',
        int $failedAttempts = 0,
        ?string $lastFailedAt = null,
        ?string $lockedAt = null,
        ?string $lockedReason = null,
    ): int {
        return (int) DB::table('users')->insertGetId([
            'username' => $email,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($password),
            'status' => $status,
            'failed_login_attempts' => $failedAttempts,
            'last_failed_login_at' => $lastFailedAt,
            'locked_at' => $lockedAt,
            'locked_reason' => $lockedReason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

