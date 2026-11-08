<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseMarkAccessTest extends TestCase
{
    use RefreshDatabase;

    private $student;
    private $other;
    private $teacher;
    private $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->student = User::factory()->create(['role' => 'student']);
        $this->other = User::factory()->create(['role' => 'student']);
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        DB::table('school_sessions')->insert(['id' => 1, 'session_name' => 'Test']);
        DB::table('semesters')->insert(['id' => 1, 'semester_name' => 'Test', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'session_id' => 1]);
        DB::table('school_classes')->insert(['id' => 1, 'class_name' => 'Test', 'session_id' => 1]);
        foreach ([1, 2] as $section) {
            DB::table('sections')->insert(['id' => $section, 'section_name' => 'Test '.$section, 'room_no' => 'Test', 'class_id' => 1, 'session_id' => 1]);
        }
        DB::table('courses')->insert(['id' => 1, 'course_name' => 'Test', 'course_type' => 'Core', 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
        foreach ([[$this->student, 1, 61], [$this->other, 2, 87]] as [$user, $section, $mark]) {
            DB::table('promotions')->insert(['student_id' => $user->id, 'class_id' => 1, 'section_id' => $section, 'session_id' => 1, 'id_card_number' => 'TEST-'.$user->id]);
            DB::table('final_marks')->insert(['student_id' => $user->id, 'class_id' => 1, 'section_id' => $section, 'course_id' => 1, 'semester_id' => 1, 'session_id' => 1, 'calculated_marks' => $mark, 'final_marks' => $mark]);
        }
        DB::table('grading_systems')->insert(['id' => 1, 'system_name' => 'Test', 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
        DB::table('grade_rules')->insert(['grading_system_id' => 1, 'session_id' => 1, 'point' => 4, 'grade' => 'Test', 'start_at' => 0, 'end_at' => 100]);
        DB::table('assigned_teachers')->insert(['teacher_id' => $this->teacher->id, 'semester_id' => 1, 'class_id' => 1, 'section_id' => 1, 'course_id' => 1, 'session_id' => 1]);
        $this->context = ['session_id' => 1, 'semester_id' => 1, 'class_id' => 1, 'section_id' => 1, 'course_id' => 1, 'student_id' => $this->student->id, 'course_name' => 'Test'];
    }

    private function url(array $changes = []): string
    {
        return '/marks/view?'.http_build_query(array_replace($this->context, $changes));
    }

    public function test_student_can_read_own_marks()
    {
        $this->actingAs($this->student)->get($this->url())->assertOk()
            ->assertViewHas('final_marks', function ($marks) {
                return $marks->count() === 1 && (int) $marks->first()->final_marks === 61;
            });
    }

    public function test_phase_two_request_for_another_student_is_forbidden()
    {
        $this->actingAs($this->student)->get($this->url(['student_id' => $this->other->id, 'section_id' => 2]))
            ->assertForbidden()->assertDontSee('>87<', false);
    }

    public function test_foreign_student_with_own_section_is_also_forbidden()
    {
        $this->actingAs($this->student)->get($this->url(['student_id' => $this->other->id]))->assertForbidden();
    }

    public function test_student_cannot_change_the_academic_context()
    {
        foreach (['section_id', 'course_id', 'semester_id', 'session_id', 'class_id'] as $field) {
            $this->actingAs($this->student)->get($this->url([$field => 999]))->assertForbidden();
        }
    }

    public function test_assigned_teacher_can_read_but_cannot_cross_sections()
    {
        $this->actingAs($this->teacher)->get($this->url())->assertOk();
        $this->get($this->url(['student_id' => $this->other->id, 'section_id' => 2]))->assertForbidden();
    }

    public function test_admin_can_read_valid_student_context()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get($this->url(['student_id' => $this->other->id, 'section_id' => 2]))->assertOk();
    }

    public function test_unsupported_role_is_forbidden()
    {
        $user = User::factory()->create(['role' => 'unassigned']);
        $this->actingAs($user)->get($this->url())->assertForbidden();
    }

    public function test_invalid_or_missing_identifiers_are_rejected()
    {
        foreach (['0 OR 1=1', 0, -1, ['1'], ''] as $value) {
            $this->actingAs($this->student)->getJson($this->url(['student_id' => $value]))
                ->assertStatus(422)->assertJsonValidationErrors('student_id');
        }
    }

    public function test_anonymous_request_redirects_to_login()
    {
        $this->get($this->url())->assertRedirect('/login');
    }
}
