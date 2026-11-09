<?php

namespace App\Http\Requests;

class StoreFinalMarksRequest extends StoreMarksRequest
{
    public function rules()
    {
        $rules = parent::rules();
        unset($rules['student_mark'], $rules['student_mark.*'], $rules['student_mark.*.*']);
        return $rules + [
            'final_mark' => 'required|array|min:1',
            'final_mark.*' => 'required|numeric|min:0',
            'note' => 'sometimes|array',
            'note.*' => 'nullable|string|max:1000',
        ];
    }
}
