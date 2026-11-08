<?php

namespace App\Repositories;

use App\Interfaces\ExamRuleInterface;
use App\Models\ExamRule;
use Illuminate\Support\Facades\DB;

class ExamRuleRepository implements ExamRuleInterface {
    public function create($request) {
        try {
            ExamRule::create($request);
        } catch (\Exception $e) {
            throw new \Exception('Failed to create exam rule. '.$e->getMessage());
        }
    }

    public function update($request) {
        try {
            DB::transaction(function () use ($request) {
                $updated = ExamRule::where('id', $request->exam_rule_id)->lockForUpdate()->firstOrFail()->update([
                    'total_marks'   => $request->total_marks,
                    'pass_marks'    => $request->pass_marks,
                    'marks_distribution_note'   => $request->marks_distribution_note
                ]);
                if (!$updated) {
                    throw new \RuntimeException('Exam rule update was cancelled.');
                }
            });
        } catch (\Exception $e) {
            throw new \Exception('Failed to update exam rule. '.$e->getMessage());
        }
    }

    public function getAll($session_id, $exam_id) {
        return ExamRule::where('session_id', $session_id)
                        ->where('exam_id', $exam_id)
                        ->get();
    }

    public function getById($exam_rule_id) {
        return ExamRule::where('id', $exam_rule_id)
                        ->first();
    }
}