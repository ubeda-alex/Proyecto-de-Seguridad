<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\MarkRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarkValidationTest extends TestCase
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
            DB::table('exam_rules')->insert(['exam_id' => $id, 'session_id' => 1, 'total_marks' => 100, 'pass_marks' => 70, 'marks_distribution_note' => 'Test scale']);
        }
        foreach ([[$this->student, 1], [$this->second, 1], [$this->other, 2]] as [$user, $section]) {
            DB::table('promotions')->insert(['student_id' => $user->id, 'class_id' => 1, 'section_id' => $section, 'session_id' => 1, 'id_card_number' => 'TEST-'.$user->id]);
        }
        DB::table('assigned_teachers')->insert(['teacher_id' => $this->teacher->id, 'semester_id' => 1, 'class_id' => 1, 'section_id' => 1, 'course_id' => 1, 'session_id' => 1]);
        $this->context = ['session_id' => 1, 'semester_id' => 1, 'class_id' => 1, 'section_id' => 1, 'course_id' => 1];
        DB::table('marks')->insert($this->row($this->other->id, 87, 2));
        DB::table('marks')->insert($this->row($this->student->id, 61));
        DB::table('marks')->insert($this->row($this->second->id, 72));
        DB::table('academic_settings')->update(['marks_submission_status' => 'on']);
        DB::table('grading_systems')->insert(['id' => 1, 'system_name' => 'Test', 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
        DB::table('grade_rules')->insert(['grading_system_id' => 1, 'session_id' => 1, 'point' => 4, 'grade' => 'Test', 'start_at' => 0, 'end_at' => 100]);
    }

    private function row($student, $mark, $section = 1): array
    {
        return ['student_id' => $student, 'exam_id' => 1, 'marks' => $mark, 'section_id' => $section, 'course_id' => 1, 'class_id' => 1, 'session_id' => 1];
    }

    private function submit(array $changes = [])
    {
        return $this->actingAs($this->teacher)->postJson('/marks/final/submit', array_replace($this->context, [
            'final_mark' => [$this->student->id => 61],
            'calculated_mark' => [$this->student->id => 999],
        ], $changes));
    }

    public function test_phase_two_values_999_are_rejected_without_saving()
    {
        $this->submit(['final_mark' => [$this->student->id => 999]])->assertStatus(422);
        $this->assertDatabaseCount('final_marks', 0);
    }

    public function test_client_calculated_mark_is_ignored_and_server_sum_is_stored()
    {
        $this->submit()->assertRedirect();
        $this->assertDatabaseHas('final_marks', ['student_id' => $this->student->id, 'calculated_marks' => 61, 'final_marks' => 61]);
    }

    public function test_calculated_field_is_not_required()
    {
        $this->submit(['calculated_mark' => null])->assertRedirect();
        $this->assertDatabaseHas('final_marks', ['calculated_marks' => 61]);
    }

    public function test_all_course_exams_are_summed_without_other_courses_or_sections()
    {
        DB::table('exams')->insert(['id' => 3, 'exam_name' => 'Second', 'start_date' => '2026-11-01 08:00:00', 'end_date' => '2026-11-01 09:00:00', 'course_id' => 1, 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
        DB::table('exam_rules')->insert(['exam_id' => 3, 'session_id' => 1, 'total_marks' => 50, 'pass_marks' => 30, 'marks_distribution_note' => 'Test']);
        DB::table('marks')->where('student_id', $this->student->id)->update(['marks' => 30]);
        $row = $this->row($this->student->id, 31); $row['exam_id'] = 3;
        DB::table('marks')->insert($row);
        $row['course_id'] = 2; $row['exam_id'] = 2; $row['marks'] = 99;
        DB::table('marks')->insert($row);
        $this->submit()->assertRedirect();
        $this->assertDatabaseHas('final_marks', ['student_id' => $this->student->id, 'calculated_marks' => 61]);
    }

    public function test_invalid_final_marks_are_rejected()
    {
        foreach ([-1, 100.01, 'bad', '1e309', ['61']] as $value) {
            $this->submit(['final_mark' => [$this->student->id => $value]])->assertStatus(422);
        }
        $this->assertDatabaseCount('final_marks', 0);
    }

    public function test_manual_adjustment_requires_reason_and_records_actor()
    {
        $this->submit(['final_mark' => [$this->student->id => 65]])->assertStatus(422)->assertJsonValidationErrors('note.'.$this->student->id);
        $this->submit(['final_mark' => [$this->student->id => 65], 'note' => [$this->student->id => 'Approved correction']])->assertRedirect();
        $this->assertDatabaseHas('final_marks', ['final_marks' => 65, 'calculated_marks' => 61, 'note' => 'Approved correction']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'App\\Models\\FinalMark', 'event' => 'model.created', 'user_id' => $this->teacher->id]);
    }

    public function test_configured_scale_is_used_instead_of_hardcoded_100()
    {
        DB::table('grade_rules')->update(['end_at' => 200]);
        $this->submit(['final_mark' => [$this->student->id => 150], 'note' => [$this->student->id => 'Scale 200 adjustment']])->assertRedirect();
        $this->assertDatabaseHas('final_marks', ['final_marks' => 150]);
    }

    public function test_gaps_in_grading_scale_are_not_accepted()
    {
        DB::table('marks')->where('student_id', $this->student->id)->update(['marks' => 40]);
        DB::table('grade_rules')->update(['end_at' => 50]);
        DB::table('grade_rules')->insert(['grading_system_id' => 1, 'session_id' => 1, 'point' => 4, 'grade' => 'High', 'start_at' => 70, 'end_at' => 100]);
        $this->submit(['final_mark' => [$this->student->id => 65], 'note' => [$this->student->id => 'Not in range']])->assertStatus(422);
    }

    public function test_missing_or_invalid_exam_rules_are_rejected()
    {
        DB::table('exam_rules')->where('exam_id', 1)->update(['total_marks' => -1]);
        $this->submit()->assertStatus(422);
        DB::table('exam_rules')->where('exam_id', 1)->delete();
        $this->submit()->assertStatus(422);
        $this->assertDatabaseCount('final_marks', 0);
    }

    public function test_ambiguous_exam_rules_are_rejected()
    {
        DB::table('exam_rules')->insert(['exam_id' => 1, 'session_id' => 1, 'total_marks' => 100, 'pass_marks' => 70, 'marks_distribution_note' => 'Duplicate']);
        $this->submit()->assertStatus(422);
    }

    public function test_missing_or_invalid_final_scale_is_rejected()
    {
        DB::table('grade_rules')->update(['start_at' => 100, 'end_at' => 0]);
        $this->submit()->assertStatus(422);
        DB::table('grade_rules')->delete();
        $this->submit()->assertStatus(422);
        DB::table('grading_systems')->delete();
        $this->submit()->assertStatus(422);
    }

    public function test_missing_duplicate_or_out_of_range_saved_marks_are_rejected()
    {
        DB::table('marks')->where('student_id', $this->student->id)->update(['marks' => 999]);
        $this->submit()->assertStatus(422);
        DB::table('marks')->where('student_id', $this->student->id)->update(['marks' => 61]);
        DB::table('marks')->insert($this->row($this->student->id, 61));
        $this->submit()->assertStatus(422);
        DB::table('marks')->where('student_id', $this->student->id)->delete();
        $this->submit()->assertStatus(422);
        $this->assertDatabaseCount('final_marks', 0);
    }

    public function test_mixed_final_batch_is_not_partially_saved()
    {
        $this->submit(['final_mark' => [$this->student->id => 61, $this->second->id => 999]])->assertStatus(422);
        $this->assertDatabaseCount('final_marks', 0);
    }

    public function test_foreign_student_and_context_are_denied()
    {
        $this->submit(['final_mark' => [$this->other->id => 87]])->assertForbidden();
        foreach (['session_id','semester_id','class_id','section_id','course_id'] as $field) {
            $this->submit([$field => 999])->assertForbidden();
        }
    }

    public function test_permission_assignment_and_submission_window_are_enforced()
    {
        $this->teacher->revokePermissionTo('save marks');
        $this->submit()->assertForbidden();
        $this->teacher->givePermissionTo('save marks');
        DB::table('academic_settings')->update(['marks_submission_status' => 'off']);
        $this->submit()->assertForbidden();
        DB::table('academic_settings')->update(['marks_submission_status' => 'on']);
        DB::table('assigned_teachers')->delete();
        $this->submit()->assertForbidden();
    }

    public function test_repeated_final_submission_and_later_exam_edits_are_denied()
    {
        $this->submit()->assertRedirect();
        $this->submit(['final_mark' => [$this->student->id => 70], 'note' => [$this->student->id => 'Overwrite']])->assertStatus(422);
        $this->postJson('/marks/store', $this->context + ['student_mark' => [$this->student->id => [1 => 70]]])->assertStatus(422);
        $this->assertDatabaseHas('final_marks', ['final_marks' => 61]);
        $this->assertDatabaseHas('marks', ['student_id' => $this->student->id, 'marks' => 61]);
    }

    public function test_exam_marks_obey_configured_maximum_and_allow_boundaries()
    {
        foreach ([999, -1, '1e309'] as $value) {
            $this->actingAs($this->teacher)->postJson('/marks/store', $this->context + ['student_mark' => [$this->student->id => [1 => $value]]])->assertStatus(422);
        }
        foreach ([0, 100] as $value) {
            $this->postJson('/marks/store', $this->context + ['student_mark' => [$this->student->id => [1 => $value]]])->assertRedirect();
            $this->assertDatabaseHas('marks', ['student_id' => $this->student->id, 'marks' => $value]);
        }
        DB::table('exam_rules')->where('exam_id', 1)->update(['total_marks' => 50, 'pass_marks' => 30]);
        $this->postJson('/marks/store', $this->context + ['student_mark' => [$this->student->id => [1 => 51]]])->assertStatus(422);
    }

    public function test_invalid_exam_batch_does_not_update_any_mark()
    {
        $this->actingAs($this->teacher)->postJson('/marks/store', $this->context + ['student_mark' => [$this->student->id => [1 => 70], $this->second->id => [1 => 999]]])->assertStatus(422);
        $this->assertDatabaseHas('marks', ['student_id' => $this->student->id, 'marks' => 61]);
    }

    public function test_final_form_keeps_existing_url_and_requires_assignment()
    {
        $context = $this->context; unset($context['session_id']);
        $this->actingAs($this->teacher)->get('/marks/final/submit?'.http_build_query($context))->assertOk();
        $this->actingAs($this->other)->get('/marks/final/submit?'.http_build_query($context))->assertForbidden();
    }

    public function test_anonymous_final_request_redirects_to_login()
    {
        $this->post('/marks/final/submit', $this->context + ['final_mark' => [$this->student->id => 61]])->assertRedirect('/login');
    }

    public function test_final_batch_and_audit_roll_back_if_second_write_fails()
    {
        $this->withoutExceptionHandling();
        $secondId = $this->second->id;
        \App\Models\FinalMark::creating(function ($mark) use ($secondId) {
            if ((int) $mark->student_id === (int) $secondId) {
                throw new \RuntimeException('Simulated write failure');
            }
        });
        try {
            $this->submit(['final_mark' => [$this->student->id => 61, $this->second->id => 72]]);
            $this->fail('The second write must fail.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Simulated write failure', $exception->getMessage());
        } finally {
            \App\Models\FinalMark::flushEventListeners();
        }
        $this->assertDatabaseCount('final_marks', 0);
        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => 'App\\Models\\FinalMark', 'event' => 'model.created']);
    }
}
