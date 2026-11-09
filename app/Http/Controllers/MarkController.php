<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mark;
use App\Models\Course;
use App\Models\Promotion;
use App\Models\AssignedTeacher;
use App\Models\Exam;
use App\Models\Section;
use App\Http\Requests\StoreMarksRequest;
use Illuminate\Http\Request;
use App\Traits\SchoolSession;
use App\Interfaces\UserInterface;
use App\Interfaces\CourseInterface;
use App\Interfaces\SectionInterface;
use App\Repositories\ExamRepository;
use App\Repositories\MarkRepository;
use App\Traits\AssignedTeacherCheck;
use App\Interfaces\SemesterInterface;
use App\Interfaces\SchoolClassInterface;
use App\Repositories\GradeRuleRepository;
use App\Interfaces\SchoolSessionInterface;
use App\Interfaces\AcademicSettingInterface;
use App\Repositories\GradingSystemRepository;

class MarkController extends Controller
{
    use SchoolSession, AssignedTeacherCheck;

    protected $academicSettingRepository;
    protected $userRepository;
    protected $schoolClassRepository;
    protected $schoolSectionRepository;
    protected $courseRepository;
    protected $semesterRepository;
    protected $schoolSessionRepository;

    public function __construct(
        AcademicSettingInterface $academicSettingRepository,
        UserInterface $userRepository,
        SchoolSessionInterface $schoolSessionRepository,
        SchoolClassInterface $schoolClassRepository,
        SectionInterface $schoolSectionRepository,
        CourseInterface $courseRepository,
        SemesterInterface $semesterRepository
    ) {
        $this->academicSettingRepository = $academicSettingRepository;
        $this->userRepository = $userRepository;
        $this->schoolSessionRepository = $schoolSessionRepository;
        $this->schoolClassRepository = $schoolClassRepository;
        $this->schoolSectionRepository = $schoolSectionRepository;
        $this->courseRepository = $courseRepository;
        $this->semesterRepository = $semesterRepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $class_id = $request->query('class_id', 0);
        $section_id = $request->query('section_id', 0);
        $course_id = $request->query('course_id', 0);
        $semester_id = $request->query('semester_id', 0);

        $current_school_session_id = $this->getSchoolCurrentSession();

        $semesters = $this->semesterRepository->getAll($current_school_session_id);

        $school_classes = $this->schoolClassRepository->getAllBySession($current_school_session_id);

        $markRepository = new MarkRepository();
        $marks = $markRepository->getAllFinalMarks($current_school_session_id, $semester_id, $class_id, $section_id, $course_id);

        if(!$marks) {
            return abort(404);
        }

        $gradingSystemRepository = new GradingSystemRepository();
        $gradingSystem = $gradingSystemRepository->getGradingSystem($current_school_session_id, $semester_id, $class_id);

        if(!$gradingSystem) {
            return abort(404);
        }

        $gradeRulesRepository = new GradeRuleRepository();
        $gradingSystemRules = $gradeRulesRepository->getAll($current_school_session_id, $gradingSystem->id);

        if(!$gradingSystemRules) {
            return abort(404);
        }

        foreach($marks as $mark_key => $mark) {
            foreach ($gradingSystemRules as $key => $gradingSystemRule) {
                if($mark->final_marks >= $gradingSystemRule->start_at && $mark->final_marks <= $gradingSystemRule->end_at) {
                    $marks[$mark_key]['point'] = $gradingSystemRule->point;
                    $marks[$mark_key]['grade'] = $gradingSystemRule->grade;
                }
            }
        }

        $data = [
            'current_school_session_id' => $current_school_session_id,
            'semesters'                 => $semesters,
            'classes'                   => $school_classes,
            'marks'                     => $marks,
            'grading_system_rules'      => $gradingSystemRules,
        ];

        return view('marks.results', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $class_id = $request->query('class_id');
        $section_id = $request->query('section_id');
        $course_id = $request->query('course_id');
        $semester_id = $request->query('semester_id', 0);
        
        try{

            $current_school_session_id = $this->getSchoolCurrentSession();
            $this->checkIfLoggedInUserIsAssignedTeacher($request, $current_school_session_id);

            $academic_setting = $this->academicSettingRepository->getAcademicSetting();

            $examRepository = new ExamRepository();

            $exams = $examRepository->getAll($current_school_session_id, $semester_id, $class_id);

            $markRepository = new MarkRepository();
            $studentsWithMarks = $markRepository->getAll($current_school_session_id, $semester_id, $class_id, $section_id, $course_id);
            $studentsWithMarks = $studentsWithMarks->groupBy('student_id');

            $sectionStudents = $this->userRepository->getAllStudents($current_school_session_id, $class_id, $section_id);

            $final_marks_submitted = false;
            
            $final_marks_submit_count = $markRepository->getFinalMarksCount($current_school_session_id, $semester_id, $class_id, $section_id, $course_id);

            if($final_marks_submit_count > 0) {
                $final_marks_submitted = true;
            }

            $data = [
                'academic_setting'          => $academic_setting,
                'exams'                     => $exams,
                'students_with_marks'       => $studentsWithMarks,
                'class_id'                  => $class_id,
                'section_id'                => $section_id,
                'course_id'                 => $course_id,
                'semester_id'               => $semester_id,
                'final_marks_submitted'     => $final_marks_submitted,
                'sectionStudents'           => $sectionStudents,
                'current_school_session_id' => $current_school_session_id,
            ];

            return view('marks.create', $data);
        } catch (\Exception $e) {
            return back()->withError($e->getMessage());
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function showFinalMark(Request $request)
    {
        $class_id = $request->query('class_id');
        $section_id = $request->query('section_id');
        $course_id = $request->query('course_id');
        $semester_id = $request->query('semester_id', 0);

        $current_school_session_id = $this->getSchoolCurrentSession();

        $markRepository = new MarkRepository();
        $studentsWithMarks = $markRepository->getAll($current_school_session_id, $semester_id, $class_id, $section_id, $course_id);
        $studentsWithMarks = $studentsWithMarks->groupBy('student_id');

        $data = [
            'students_with_marks'       => $studentsWithMarks,
            'class_id'                  => $class_id,
            'class_name'                => $request->query('class_name'),
            'section_id'                => $section_id,
            'section_name'              => $request->query('section_name'),
            'course_id'                 => $course_id,
            'course_name'               => $request->query('course_name'),
            'semester_id'               => $semester_id,
            'current_school_session_id' => $current_school_session_id,
        ];

        return view('marks.submit-final-marks', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreMarksRequest $request)
    {
        $input = $request->validated();
        $current_school_session_id = $this->getSchoolCurrentSession();
        abort_unless((int) $input['session_id'] === (int) $current_school_session_id, 403);
        abort_unless(AssignedTeacher::where([
            'teacher_id' => $request->user()->id,
            'session_id' => $input['session_id'],
            'semester_id' => $input['semester_id'],
            'class_id' => $input['class_id'],
            'section_id' => $input['section_id'],
            'course_id' => $input['course_id'],
        ])->exists(), 404);
        abort_unless(Course::where('id', $input['course_id'])->where([
            'session_id' => $input['session_id'],
            'semester_id' => $input['semester_id'],
            'class_id' => $input['class_id'],
        ])->exists(), 403);
        abort_unless(Section::where('id', $input['section_id'])->where([
            'session_id' => $input['session_id'],
            'class_id' => $input['class_id'],
        ])->exists(), 403);

        // Validate the entire batch before writing any row.
        $studentIds = array_keys($input['student_mark']);
        $examIds = [];
        foreach ($input['student_mark'] as $studentId => $marks) {
            abort_unless(ctype_digit((string) $studentId) && (int) $studentId > 0, 422);
            foreach (array_keys($marks) as $examId) {
                abort_unless(ctype_digit((string) $examId) && (int) $examId > 0, 422);
                $examIds[] = $examId;
            }
        }
        $enrolledIds = Promotion::where([
            'session_id' => $input['session_id'],
            'class_id' => $input['class_id'],
            'section_id' => $input['section_id'],
        ])->whereIn('student_id', $studentIds)
            ->whereHas('student', function ($query) {
                $query->where('role', 'student');
            })->pluck('student_id')->all();
        abort_unless(count(array_diff($studentIds, $enrolledIds)) === 0, 403);
        $examIds = array_unique($examIds);
        $validExamCount = Exam::whereIn('id', $examIds)->where([
            'session_id' => $input['session_id'],
            'semester_id' => $input['semester_id'],
            'class_id' => $input['class_id'],
            'course_id' => $input['course_id'],
        ])->count();
        abort_unless($validExamCount === count($examIds), 403);

        $rows = [];
        foreach($input['student_mark'] as $id => $stm) {
            foreach($stm as $exam => $mark){
                $row = [];
                $row['class_id'] = $request->class_id;
                $row['student_id'] = $id;
                $row['marks'] = $mark;
                $row['section_id'] = $request->section_id;
                $row['course_id'] = $request->course_id;
                $row['session_id'] = $request->session_id;
                $row['exam_id'] = $exam;

                $rows[] = $row;
            }
        }
        try {
            $markRepository = new MarkRepository();
            $markRepository->create($rows);

            return back()->with('status', 'Saving marks was successful!');
        } catch (\Exception $e) {
            return back()->withError($e->getMessage());
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function storeFinalMark(Request $request) {
        $current_school_session_id = $this->getSchoolCurrentSession();

        $this->checkIfLoggedInUserIsAssignedTeacher($request, $current_school_session_id);
        $rows = [];
        foreach($request->calculated_mark as $id => $cmark) {
                $row = [];
                $row['class_id'] = $request->class_id;
                $row['student_id'] = $id;
                $row['calculated_marks'] = $cmark;
                $row['final_marks'] = $request->final_mark[$id];
                $row['note'] = $request->note[$id];
                $row['section_id'] = $request->section_id;
                $row['course_id'] = $request->course_id;
                $row['session_id'] = $request->session_id;
                $row['semester_id'] = $request->semester_id;

                $rows[] = $row;
        }
        try {
            $markRepository = new MarkRepository();
            $markRepository->storeFinalMarks($rows);

            return back()->with('status', 'Submitting final marks was successful!');
        } catch (\Exception $e) {
            return back()->withError($e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function showCourseMark(Request $request)
    {
        $context = $request->validate([
            'session_id' => 'required|integer|min:1',
            'semester_id' => 'required|integer|min:1',
            'class_id' => 'required|integer|min:1',
            'section_id' => 'required|integer|min:1',
            'course_id' => 'required|integer|min:1',
            'student_id' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        if ($user->role === 'student') {
            abort_unless((int) $context['student_id'] === (int) $user->id, 403);
        } elseif ($user->role === 'teacher') {
            abort_unless(AssignedTeacher::where([
                'teacher_id' => $user->id,
                'session_id' => $context['session_id'],
                'semester_id' => $context['semester_id'],
                'class_id' => $context['class_id'],
                'section_id' => $context['section_id'],
                'course_id' => $context['course_id'],
            ])->exists(), 403);
        } else {
            abort_unless($user->role === 'admin', 403);
        }

        // Authorize the academic context before retrieving any marks.
        abort_unless(Promotion::where([
            'student_id' => $context['student_id'],
            'session_id' => $context['session_id'],
            'class_id' => $context['class_id'],
            'section_id' => $context['section_id'],
        ])->exists(), 403);
        abort_unless(Course::where('id', $context['course_id'])->where([
            'session_id' => $context['session_id'],
            'semester_id' => $context['semester_id'],
            'class_id' => $context['class_id'],
        ])->exists(), 403);

        $session_id = $request->query('session_id');
        $semester_id = $request->query('semester_id');
        $class_id = $request->query('class_id');
        $section_id = $request->query('section_id');
        $course_id = $request->query('course_id');
        $course_name = $request->query('course_name');
        $student_id = $request->query('student_id');
        $markRepository = new MarkRepository();
        $marks = $markRepository->getAllByStudentId($session_id, $semester_id, $class_id, $section_id, $course_id, $student_id);
        $finalMarks = $markRepository->getAllFinalMarksByStudentId($session_id, $student_id, $semester_id, $class_id, $section_id, $course_id);

        if(!$finalMarks) {
            return abort(404);
        }

        $gradingSystemRepository = new GradingSystemRepository();
        $gradingSystem = $gradingSystemRepository->getGradingSystem($session_id, $semester_id, $class_id);

        if(!$gradingSystem) {
            return abort(404);
        }

        $gradeRulesRepository = new GradeRuleRepository();
        $gradingSystemRules = $gradeRulesRepository->getAll($session_id, $gradingSystem->id);

        if(!$gradingSystemRules) {
            return abort(404);
        }

        foreach($finalMarks as $mark_key => $mark) {
            foreach ($gradingSystemRules as $key => $gradingSystemRule) {
                if($mark->final_marks >= $gradingSystemRule->start_at && $mark->final_marks <= $gradingSystemRule->end_at) {
                    $finalMarks[$mark_key]['point'] = $gradingSystemRule->point;
                    $finalMarks[$mark_key]['grade'] = $gradingSystemRule->grade;
                }
            }
        }

        $data = [
            'marks' => $marks,
            'final_marks'   => $finalMarks,
            'course_name' => $course_name,
        ];

        return view('marks.student', $data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Mark  $mark
     * @return \Illuminate\Http\Response
     */
    public function edit(Mark $mark)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Mark  $mark
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Mark $mark)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Mark  $mark
     * @return \Illuminate\Http\Response
     */
    public function destroy(Mark $mark)
    {
        //
    }
}
