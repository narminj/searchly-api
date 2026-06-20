<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocsAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $docsDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Point the docs route at a throwaway dir so tests never touch the real
        // storage/app/docs (which holds the production documentation)
        $this->docsDir = sys_get_temp_dir() . '/searchly-docs-test-' . uniqid();
        mkdir($this->docsDir, 0775, true);
        file_put_contents($this->docsDir . '/TECHNICAL_DOCUMENTATION.html', '<html>docs</html>');
        config(['docs.path' => $this->docsDir]);
    }

    protected function tearDown(): void
    {
        @unlink($this->docsDir . '/TECHNICAL_DOCUMENTATION.html');
        @rmdir($this->docsDir);
        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/manual')->assertRedirect('/login');
    }

    public function test_viewer_can_access_docs(): void
    {
        $this->actingAs(User::factory()->viewer()->create())->get('/manual')->assertOk();
    }

    public function test_admin_can_access_docs(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/manual')->assertOk();
    }

    public function test_viewer_cannot_access_admin_area(): void
    {
        $this->actingAs(User::factory()->viewer()->create())->get('/admin/users')->assertForbidden();
    }

    public function test_admin_can_access_admin_area(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin/users')->assertOk();
    }

    public function test_guest_is_redirected_from_admin_area(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }
}
