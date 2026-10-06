<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_changes_are_recorded_without_secret_fields()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->first_name = 'Updated name';
        $user->password = Hash::make('new-secret-password');
        $user->save();

        $auditLog = AuditLog::where('event', 'model.updated')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->id, $auditLog->user_id);
        $this->assertSame('Updated name', $auditLog->new_values['first_name']);
        $this->assertArrayNotHasKey('password', $auditLog->old_values);
        $this->assertArrayNotHasKey('password', $auditLog->new_values);
    }

    public function test_audit_page_requires_permission()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('audit-logs.index'))->assertForbidden();

        $permission = Permission::create([
            'name' => 'view audit logs',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);

        $this->get(route('audit-logs.index'))->assertOk()->assertViewIs('audit-logs.index');
    }

    public function test_authentication_events_are_recorded()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.login',
            'user_id' => $user->id,
        ]);

        $this->post('/logout');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.logout',
            'user_id' => $user->id,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.login_failed',
            'user_id' => null,
        ]);
    }
}