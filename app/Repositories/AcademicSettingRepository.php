<?php

namespace App\Repositories;

use App\Models\AcademicSetting;
use App\Interfaces\AcademicSettingInterface;
use Illuminate\Support\Facades\DB;

class AcademicSettingRepository implements AcademicSettingInterface {
    public function getAcademicSetting(){
        return AcademicSetting::find(1);
    }

    public function updateAttendanceType($request) {
        try {
            DB::transaction(function () use ($request) {
                $setting = AcademicSetting::where('id', 1)->lockForUpdate()->firstOrFail();
                $setting->attendance_type = $request['attendance_type'];
                if (!$setting->save()) {
                    throw new \RuntimeException('Attendance type update was cancelled.');
                }
            });
        } catch (\Exception $e) {
            throw new \Exception('Failed to update attendance type. '.$e->getMessage());
        }
    }

    public function updateFinalMarksSubmissionStatus($request) {
        $status = "off";
        if(isset($request['marks_submission_status'])) {
            $status = "on";
        }
        try {
            DB::transaction(function () use ($status) {
                $setting = AcademicSetting::where('id', 1)->lockForUpdate()->firstOrFail();
                $setting->marks_submission_status = $status;
                if (!$setting->save()) {
                    throw new \RuntimeException('Final marks submission status update was cancelled.');
                }
            });
        } catch (\Exception $e) {
            throw new \Exception('Failed to update final marks submission status. '.$e->getMessage());
        }
    }
}