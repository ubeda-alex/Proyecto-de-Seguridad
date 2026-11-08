<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\MarkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarkStoreAccessTest extends TestCase
{
    use RefreshDatabase;

    private $teacher;
    private $student;
    private $other;
    private $second;
    private $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->teacher->givePermissionTo('save marks');
        $this->student = User::factory()->create(['role' => 'student']);
        $this->second = User::factory()->create(['role' => 'student']);
        $this->other = User::factory()->create(['role' => 'student']);
        DB::table('school_sessions')->insert(['id' => 1, 'session_name' => 'Test']);
        DB::table('semesters')->insert(['id' => 1, 'semester_name' => 'Test', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'session_id' => 1]);
        DB::table('school_classes')->insert(['id' => 1, 'class_name' => 'Test', 'session_id' => 1]);
        foreach ([1, 2] as $id) {
            DB::table('sections')->insert(['id' => $id, 'section_name' => 'Test '.$id, 'room_no' => 'Test', 'class_id' => 1, 'session_id' => 1]);
            DB::table('courses')->insert(['id' => $id, 'course_name' => 'Test '.$id, 'course_type' => 'Core', 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
            DB::table('exams')->insert(['id' => $id, 'exam_name' => 'Test '.$id, 'start_date' => '2026-11-01 08:00:00', 'end_date' => '2026-11-01 09:00:00', 'course_id' => $id, 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
        }
        foreach ([[$this->student, 1], [$this->second, 1], [$this->other, 2]] as [$user, $section]) {
            DB::table('promotions')->insert(['student_id' => $user->id, 'class_id' => 1, 'section_id' => $section, 'session_id' => 1, 'id_card_number' => 'TEST-'.$user->id]);
        }
        DB::table('assigned_teachers')->insert(['teacher_id' => $this->teacher->id, 'semester_id' => 1, 'class_id' => 1, 'section_id' => 1, 'course_id' => 1, 'session_id' => 1]);
        $this->context = ['session_id' => 1, 'semester_id' => 1, 'class_id' => 1, 'section_id' => 1, 'course_id' => 1];
        DB::table('marks')->insert($this->row($this->other->id, 87, 2));
    }

    private function row($student, $mark, $section = 1): array
    {
        return ['student_id' => $student, 'exam_id' => 1, 'marks' => $mark, 'section_id' => $section, 'course_id' => 1, 'class_id' => 1, 'session_id' => 1];
    }

    private function submit(array $marks, array $changes = [])
    {
        return $this->actingAs($this->teacher)->postJson('/marks/store', array_replace($this->context, ['student_mark' => $marks], $changes));
    }

    public function test_phase_two_foreign_student_request_is_denied_without_writing()
    {
        $this->submit([$this->other->id => [1 => 999]])->assertForbidden();
        $this->assertDatabaseCount('marks', 1);
        $this->assertDatabaseHas('marks', ['student_id' => $this->other->id, 'section_id' => 2, 'marks' => 87]);
    }

    public function test_valid_batch_can_create_and_update_marks()
    {
        $this->submit([$this->student->id => [1 => 61], $this->second->id => [1 => 72]])->assertRedirect();
        $this->submit([$this->student->id => [1 => 65]])->assertRedirect();
        $this->assertDatabaseCount('marks', 3);
        $this->assertDatabaseHas('marks', ['student_id' => $this->student->id, 'marks' => 65]);
        $this->assertDatabaseHas('marks', ['student_id' => $this->second->id, 'marks' => 72]);
    }

    public function test_mixed_batch_is_rejected_before_updating_valid_student()
    {
        DB::table('marks')->insert($this->row($this->student->id, 61));
        $this->submit([$this->student->id => [1 => 99], $this->other->id => [1 => 999]])->assertForbidden();
        $this->assertDatabaseCount('marks', 2);
        $this->assertDatabaseHas('marks', ['student_id' => $this->student->id, 'marks' => 61]);
    }

    public function test_unknown_student_and_wrong_exam_are_rejected()
    {
        $this->submit([99999 => [1 => 70]])->assertForbidden();
        $this->submit([$this->student->id => [2 => 70]])->assertForbidden();
        $this->submit([$this->student->id => [99999 => 70]])->assertForbidden();
        $this->assertDatabaseCount('marks', 1);
    }

    public function test_request_cannot_change_school_session()
    {
        $this->submit([$this->student->id => [1 => 70]], ['session_id' => 999])->assertForbidden();
        $this->assertDatabaseCount('marks', 1);
    }

    public function test_course_and_section_must_match_context_even_with_stale_assignment()
    {
        DB::table('courses')->where('id', 1)->update(['class_id' => 2]);
        $this->submit([$this->student->id => [1 => 70]])->assertForbidden();
        DB::table('courses')->where('id', 1)->update(['class_id' => 1]);
        DB::table('sections')->where('id', 1)->update(['class_id' => 2]);
        $this->submit([$this->student->id => [1 => 70]])->assertForbidden();
        $this->assertDatabaseCount('marks', 1);
    }

    public function test_user_without_permission_or_assignment_cannot_save()
    {
        $this->teacher->revokePermissionTo('save marks');
        $this->submit([$this->student->id => [1 => 70]])->assertForbidden();
        $this->teacher->givePermissionTo('save marks');
        DB::table('assigned_teachers')->delete();
        $this->submit([$this->student->id => [1 => 70]])->assertNotFound();
        $this->assertDatabaseCount('marks', 1);
    }

    public function test_malformed_keys_and_values_are_rejected()
    {
        foreach ([['bad' => [1 => 70]], [$this->student->id => ['bad' => 70]], [$this->student->id => [1 => 'bad']], [], [$this->student->id => []]] as $marks) {
            $this->submit($marks)->assertStatus(422);
        }
        $this->assertDatabaseCount('marks', 1);
    }

    public function test_repository_rolls_back_earlier_rows_if_a_later_write_fails()
    {
        try {
            (new MarkRepository())->create([$this->row($this->student->id, 61), $this->row($this->second->id, null)]);
            $this->fail('The invalid second row must fail.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Failed to update students marks.', $exception->getMessage());
        }
        $this->assertDatabaseCount('marks', 1);
    }

    public function test_anonymous_request_redirects_to_login()
    {
        $this->post('/marks/store', $this->context + ['student_mark' => [$this->student->id => [1 => 70]]])->assertRedirect('/login');
    }
}
