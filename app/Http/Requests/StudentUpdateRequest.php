<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class StudentUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Fix UM-01 (A01 Broken Access Control): updating a user is a
     * privileged operation and must require the 'edit users' permission,
     * not merely 'view users'.
     *
     * @return bool
     */
    public function authorize()
    {
        return auth()->user()->can('edit users');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $studentId = $this->input('student_id');

        return [
            'student_id'    => [
                'required',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'student')),
            ],
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'email'         => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($studentId),
            ],
            'gender'        => 'required|string|max:50',
            'nationality'   => 'required|string|max:100',
            'phone'         => 'required|string|max:50',
            'address'       => 'required|string|max:255',
            'address2'      => 'nullable|string|max:255',
            'city'          => 'required|string|max:100',
            'zip'           => 'required|string|max:20',
            'birthday'      => 'required|date',
            'religion'      => 'required|string|max:100',
            'blood_type'    => 'required|string|max:10',

            // Parents' information
            'father_name'    => 'required|string|max:255',
            'father_phone'   => 'required|string|max:50',
            'mother_name'    => 'required|string|max:255',
            'mother_phone'   => 'required|string|max:50',
            'parent_address' => 'required|string|max:255',

            // Academic information
            'id_card_number' => 'required|string|max:100',
        ];
    }

    /**
     * Normalize optional fields so hardened NOT NULL columns never
     * receive null from an omitted input.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'address2' => (string) $this->input('address2', ''),
        ]);
    }
}
