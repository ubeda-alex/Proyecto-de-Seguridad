<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleUpdateRestrictionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_does_not_update_a_teacher_when_the_target_user_is_not_a_teacher()
    {
        $student = User::factory()->create([
            'role' => 'student',
            'email' => 'student@example.com',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to update Teacher');

        app(UserRepository::class)->updateTeacher([
            'teacher_id' => $student->id,
            'first_name' => 'Hacked',
            'last_name' => 'User',
            'email' => 'atacante@evil.test',
            'gender' => 'male',
            'nationality' => 'Test',
            'phone' => '12345678',
            'address' => 'N/A',
            'address2' => 'N/A',
            'city' => 'N/A',
            'zip' => '00000',
        ]);
    }

    /** @test */
    public function it_does_not_update_a_student_when_the_target_user_is_not_a_student()
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'email' => 'teacher@example.com',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to update Student');

        app(UserRepository::class)->updateStudent([
            'student_id' => $teacher->id,
            'first_name' => 'Hacked',
            'last_name' => 'User',
            'email' => 'atacante@evil.test',
            'gender' => 'male',
            'nationality' => 'Test',
            'phone' => '12345678',
            'address' => 'N/A',
            'address2' => 'N/A',
            'city' => 'N/A',
            'zip' => '00000',
            'birthday' => '2000-01-01',
            'religion' => 'Test',
            'blood_type' => 'O+',
        ]);
    }
}
