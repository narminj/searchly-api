<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_reachable_by_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'admin@searchly.test', 'password' => 'secret-pass-1']);

        $this->post('/login', ['email' => 'admin@searchly.test', 'password' => 'secret-pass-1'])
            ->assertRedirect('/manual');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'admin@searchly.test', 'password' => 'secret-pass-1']);

        $this->from('/login')
            ->post('/login', ['email' => 'admin@searchly.test', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_already_authenticated_user_is_sent_to_manual(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/manual');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'admin@searchly.test', 'password' => 'secret-pass-1']);

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', ['email' => 'admin@searchly.test', 'password' => 'wrong']);
        }

        // 6th attempt is throttled before reaching the controller
        $this->from('/login')
            ->post('/login', ['email' => 'admin@searchly.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
