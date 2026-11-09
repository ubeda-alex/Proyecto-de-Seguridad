<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarksRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user() && $this->user()->can('save marks');
    }

    public function rules()
    {
        return [
            'session_id' => 'required|integer|min:1',
            'semester_id' => 'required|integer|min:1',
            'class_id' => 'required|integer|min:1',
            'section_id' => 'required|integer|min:1',
            'course_id' => 'required|integer|min:1',
            'student_mark' => 'required|array|min:1',
            'student_mark.*' => 'required|array|min:1',
            'student_mark.*.*' => 'required|numeric',
        ];
    }
}
