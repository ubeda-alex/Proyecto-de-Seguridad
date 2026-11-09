<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private $teacher;
    private $students;
    private $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->teacher->givePermissionTo(['view attendances', 'take attendances']);
        $this->students = User::factory()->count(2)->create(['role' => 'student']);
        DB::table('school_sessions')->insert(['id' => 1, 'session_name' => 'Test']);
        DB::table('school_classes')->insert(['id' => 1, 'class_name' => 'Test', 'session_id' => 1]);
        DB::table('sections')->insert(['id' => 1, 'section_name' => 'A', 'room_no' => 'Test', 'class_id' => 1, 'session_id' => 1]);
        DB::table('semesters')->insert(['id' => 1, 'semester_name' => 'Test', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'session_id' => 1]);
        DB::table('courses')->insert(['id' => 1, 'course_name' => 'Test', 'course_type' => 'Core', 'class_id' => 1, 'semester_id' => 1, 'session_id' => 1]);
        foreach ($this->students as $student) {
            DB::table('promotions')->insert(['student_id' => $student->id, 'class_id' => 1, 'section_id' => 1, 'session_id' => 1, 'id_card_number' => 'TEST-'.$student->id]);
        }
        $id = $this->students[0]->id;
        $this->payload = ['class_id' => 1, 'section_id' => 1, 'course_id' => 1, 'session_id' => 1, 'student_ids' => [$id], 'status' => [$id => 'on']];
    }

    private function attendanceEvents()
    {
        return AuditLog::where('auditable_type', Attendance::class)->where('event', 'model.created')->get();
    }

    public function test_phase_two_attendance_now_creates_its_audit_event()
    {
        $this->actingAs($this->teacher)->post('/attendances', $this->payload)->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseCount('attendances', 1);
        $events = $this->attendanceEvents();
        $this->assertCount(1, $events);
        $event = $events->first();
        $attendance = Attendance::firstOrFail();
        $this->assertSame((int) $attendance->id, (int) $event->auditable_id);
        $this->assertSame($this->teacher->id, $event->user_id);
        $this->assertSame($this->teacher->email, $event->actor_email);
        $this->assertSame($this->students[0]->id, (int) $event->new_values['student_id']);
        $this->assertSame('on', $event->new_values['status']);
        foreach (['class_id', 'section_id', 'course_id'] as $field) {
            $this->assertSame(1, (int) $event->new_values[$field]);
        }
        $this->assertNotEmpty($event->created_at);
    }

    public function test_batch_records_one_event_per_attendance_and_preserves_absence()
    {
        $payload = $this->payload;
        $payload['student_ids'] = $this->students->pluck('id')->all();
        $this->actingAs($this->teacher)->post('/attendances', $payload)->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseCount('attendances', 2);
        $events = $this->attendanceEvents();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(Attendance::pluck('id')->all(), $events->pluck('auditable_id')->all());
        $this->assertDatabaseHas('attendances', ['student_id' => $this->students[0]->id, 'status' => 'on']);
        $this->assertDatabaseHas('attendances', ['student_id' => $this->students[1]->id, 'status' => 'off']);
    }

    public function test_audit_failure_does_not_leave_an_unaudited_attendance()
    {
        $logger = \Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit storage unavailable'));
        $this->app->instance(AuditLogger::class, $logger);
        $this->actingAs($this->teacher)->post('/attendances', $this->payload)->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('attendances', 0);
        $this->assertCount(0, $this->attendanceEvents());
    }

    public function test_failure_on_second_row_rolls_back_first_attendance_and_its_event()
    {
        $secondId = $this->students[1]->id;
        Attendance::creating(function ($attendance) use ($secondId) {
            if ((int) $attendance->student_id === (int) $secondId) {
                throw new \RuntimeException('Simulated write failure');
            }
        });
        $payload = $this->payload;
        $payload['student_ids'] = $this->students->pluck('id')->all();
        try {
            $this->actingAs($this->teacher)->post('/attendances', $payload)->assertRedirect()->assertSessionHas('error');
        } finally {
            Attendance::flushEventListeners();
        }
        $this->assertDatabaseCount('attendances', 0);
        $this->assertCount(0, $this->attendanceEvents());
    }

    public function test_permissions_still_block_unauthorized_requests()
    {
        $this->teacher->revokePermissionTo('take attendances');
        $this->actingAs($this->teacher)->post('/attendances', $this->payload)->assertForbidden();
        $this->teacher->givePermissionTo('take attendances');
        $this->teacher->revokePermissionTo('view attendances');
        $this->post('/attendances', $this->payload)->assertForbidden();
        $this->assertDatabaseCount('attendances', 0);
        $this->assertCount(0, $this->attendanceEvents());
    }

    public function test_malformed_request_does_not_create_attendance_or_event()
    {
        $payload = $this->payload;
        $payload['student_ids'] = ['invalid'];
        $this->actingAs($this->teacher)->postJson('/attendances', $payload)->assertStatus(422);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertCount(0, $this->attendanceEvents());
    }

    public function test_anonymous_request_redirects_to_login()
    {
        $this->post('/attendances', $this->payload)->assertRedirect('/login');
        $this->assertDatabaseCount('attendances', 0);
        $this->assertCount(0, $this->attendanceEvents());
    }

    public function test_existing_csrf_control_rejects_request_without_token()
    {
        // Enable the production CSRF path instead of the test-environment bypass.
        $this->app['env'] = 'local';
        $this->actingAs($this->teacher)->post('/attendances', $this->payload)->assertStatus(419);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertCount(0, $this->attendanceEvents());
    }

    public function test_existing_logger_sanitization_and_signatures_remain_active()
    {
        $this->teacher->first_name = "Teacher\n<injected>";
        $this->teacher->save();
        $this->actingAs($this->teacher)->post('/attendances', $this->payload)->assertRedirect();
        $event = $this->attendanceEvents()->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString("\n", $event->actor_name);
        $this->assertStringNotContainsString('<', $event->actor_name);
        $values = $event->new_values;
        $signature = $values['__signature'];
        unset($values['__signature']);
        $expected = hash_hmac('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
        $this->assertSame($expected, $signature);
    }

    public function test_timestamps_and_existing_read_queries_are_preserved()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:30:00'));
        try {
            $this->actingAs($this->teacher)->post('/attendances', $this->payload)->assertRedirect();
            $attendance = Attendance::firstOrFail();
            $this->assertSame('2026-11-09 10:30:00', $attendance->created_at->format('Y-m-d H:i:s'));
            $repository = new AttendanceRepository();
            $this->assertCount(1, $repository->getSectionAttendance(1, 1, 1));
            $this->assertCount(1, $repository->getCourseAttendance(1, 1, 1));
            $this->assertCount(1, $repository->getStudentAttendance(1, $this->students[0]->id));
        } finally {
            Carbon::setTestNow();
        }
    }
}
