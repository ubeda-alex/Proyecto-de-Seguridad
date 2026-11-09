<?php

namespace App\Services;

use App\Models\ExamRule;
use App\Models\GradeRule;
use App\Models\GradingSystem;
use Illuminate\Validation\ValidationException;

class MarkValidation
{
    public function examLimits(array $examIds, $sessionId): array
    {
        $rules = ExamRule::where('session_id', $sessionId)->whereIn('exam_id', $examIds)->get()->groupBy('exam_id');
        $limits = [];
        foreach ($examIds as $id) {
            $entries = $rules->get($id, collect());
            if ($entries->count() !== 1) {
                $this->reject('student_mark', 'Each exam must have exactly one valid marking rule.');
            }
            $rule = $entries->first();
            if (!$this->finite($rule->total_marks) || $rule->total_marks <= 0
                || !$this->finite($rule->pass_marks) || $rule->pass_marks < 0 || $rule->pass_marks > $rule->total_marks) {
                $this->reject('student_mark', 'The exam marking rule is invalid.');
            }
            $limits[$id] = (float) $rule->total_marks;
        }
        return $limits;
    }

    public function examMark($value, $maximum, string $field): void
    {
        if (!$this->finite($value) || $value < 0 || $value > $maximum) {
            $this->reject($field, 'The mark must be between zero and the configured exam maximum.');
        }
    }

    public function finalRanges(array $context)
    {
        $systems = GradingSystem::where('session_id', $context['session_id'])
            ->where('semester_id', $context['semester_id'])->where('class_id', $context['class_id'])->get();
        if ($systems->count() !== 1) {
            $this->reject('final_mark', 'Exactly one grading system is required for this context.');
        }
        $ranges = GradeRule::where('session_id', $context['session_id'])->where('grading_system_id', $systems->first()->id)->get();
        if ($ranges->isEmpty()) {
            $this->reject('final_mark', 'The grading scale is missing.');
        }
        foreach ($ranges as $range) {
            if (!$this->finite($range->start_at) || !$this->finite($range->end_at)
                || $range->start_at < 0 || $range->end_at < $range->start_at) {
                $this->reject('final_mark', 'The grading scale is invalid.');
            }
        }
        return $ranges;
    }

    public function finalMark($value, $ranges, string $field): void
    {
        if ($this->finite($value)) {
            foreach ($ranges as $range) {
                if ($value >= $range->start_at && $value <= $range->end_at) {
                    return;
                }
            }
        }
        $this->reject($field, 'The mark is outside the configured grading scale.');
    }

    public function reject(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function finite($value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }
}
