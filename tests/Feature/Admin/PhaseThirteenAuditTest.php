<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThirteenAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_facets_requires_permission(): void
    {
        $this->getJson('/api/admin/audit-logs/facets')->assertStatus(401);

        $this->actingAsAdminWithPermissions(['ops.dashboard.view']);
        $this->getJson('/api/admin/audit-logs/facets')->assertStatus(403);
    }

    public function test_facets_returns_distinct_sorted_values(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        $this->log(['module' => 'admin.users', 'action' => 'create', 'result' => 'success']);
        $this->log(['module' => 'admin.users', 'action' => 'update', 'result' => 'success']);
        $this->log(['module' => 'ops.release', 'action' => 'run', 'result' => 'failure']);

        $this->getJson('/api/admin/audit-logs/facets')
            ->assertOk()
            ->assertJsonPath('data.modules', ['admin.users', 'ops.release'])
            ->assertJsonPath('data.actions', ['create', 'run', 'update'])
            ->assertJsonPath('data.results', ['failure', 'success']);
    }

    public function test_index_keyword_filters_across_fields(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        $this->log(['admin_email' => 'alice@example.com', 'module' => 'admin.users', 'action' => 'create']);
        $this->log(['admin_email' => 'bob@example.com', 'module' => 'ops.docker', 'action' => 'restart']);

        $this->getJson('/api/admin/audit-logs?keyword=alice')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.admin_email', 'alice@example.com');

        $this->getJson('/api/admin/audit-logs?keyword=docker')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.module', 'ops.docker');
    }

    public function test_export_streams_all_matching_rows_without_5000_cap(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);

        $rows = [];
        for ($i = 0; $i < 5001; $i++) {
            $rows[] = [
                'admin_email' => 'a@example.com',
                'module' => 'admin.users',
                'action' => 'create',
                'result' => 'success',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        AdminAuditLog::query()->insert($rows);

        $content = $this->get('/api/admin/audit-logs/export')
            ->assertOk()
            ->streamedContent();

        // header + 5001 data rows
        $this->assertGreaterThanOrEqual(5002, substr_count($content, "\n"));
    }

    public function test_export_escapes_formula_injection_in_message(): void
    {
        $this->actingAsAdminWithPermissions(['admin.audit.view']);
        $this->log(['module' => 'admin.users', 'action' => 'create', 'message' => '=HYPERLINK("http://evil")']);

        $content = $this->get('/api/admin/audit-logs/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    private function log(array $attributes): AdminAuditLog
    {
        return AdminAuditLog::query()->create(array_merge([
            'admin_email' => 'admin@example.com',
            'module' => 'admin.users',
            'action' => 'create',
            'result' => 'success',
            'status_code' => 200,
        ], $attributes));
    }
}
