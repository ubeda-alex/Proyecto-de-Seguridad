<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regression tests for the Gestión de Usuarios module (Fase 3).
 *
 * Covers the remediated findings:
 *  - UM-01  Broken Access Control / IDOR on user updates (A01).
 *  - UM-03b Audit Poisoning / evasion: builder update bypassed the observer.
 */
class UserManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createPermissions(): void
    {
        Permission::findOrCreate('view users');
        Permission::findOrCreate('edit users');
    }

    private function makeTeacher(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role'       => 'teacher',
            'first_name' => 'Victor',
            'last_name'  => 'Victim',
        ], $overrides));
    }

    private function updatePayload(User $teacher, array $overrides = []): array
    {
        return array_merge([
            'teacher_id'  => $teacher->id,
            'first_name'  => 'Victor',
            'last_name'   => 'Victim',
            'email'       => $teacher->email,
            'gender'      => 'male',
            'nationality' => 'CR',
            'phone'       => '123',
            'address'     => 'Addr 1',
            'address2'    => '',
            'city'        => 'San Jose',
            'zip'         => '10000',
        ], $overrides);
    }

    /**
     * @test
     * UM-01: a user holding only 'view users' must NOT be able to update
     * another user. The endpoint must require 'edit users'.
     */
    public function user_without_edit_permission_cannot_update_another_user()
    {
        $this->createPermissions();

        $attacker = User::factory()->create(['role' => 'teacher']);
        $attacker->givePermissionTo('view users'); // low privilege only

        $victim = $this->makeTeacher(['email' => 'victim@ut.com']);

        $response = $this->actingAs($attacker)->post(
            route('school.teacher.update'),
            $this->updatePayload($victim, [
                'first_name' => 'PWNED',
                'email'      => 'attacker@evil.com',
            ])
        );

        $response->assertForbidden(); // 403

        $this->assertDatabaseHas('users', [
            'id'         => $victim->id,
            'email'      => 'victim@ut.com',
            'first_name' => 'Victor',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'attacker@evil.com']);
    }

    /**
     * @test
     * UM-01: a user with 'edit users' can update, and
     * UM-03b: the change is recorded in the audit log (no longer evaded).
     */
    public function privileged_update_succeeds_and_is_audited()
    {
        $this->createPermissions();

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->givePermissionTo('view users', 'edit users');

        $victim = $this->makeTeacher(['email' => 'victim@ut.com']);

        $response = $this->actingAs($admin)->post(
            route('school.teacher.update'),
            $this->updatePayload($victim, ['first_name' => 'VictorEdited'])
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id'         => $victim->id,
            'first_name' => 'VictorEdited',
        ]);

        // Audit evasion is closed: the builder update was replaced by a model
        // update, so the observer fires and records a 'model.updated' event.
        $this->assertDatabaseHas('audit_logs', [
            'event'          => 'model.updated',
            'auditable_type' => User::class,
            'auditable_id'   => $victim->id,
        ]);
    }

    /**
     * @test
     * UM-01: the update must not let a teacher endpoint touch a non-teacher
     * record (role-scoped target).
     */
    public function privileged_update_cannot_target_wrong_role()
    {
        $this->createPermissions();

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->givePermissionTo('view users', 'edit users');

        $student = User::factory()->create(['role' => 'student', 'email' => 'stud@ut.com']);

        $response = $this->actingAs($admin)->post(
            route('school.teacher.update'),
            $this->updatePayload($student, ['first_name' => 'ShouldNotApply'])
        );

        $response->assertSessionHasErrors('teacher_id');
        $this->assertDatabaseMissing('users', ['first_name' => 'ShouldNotApply']);
    }
}
