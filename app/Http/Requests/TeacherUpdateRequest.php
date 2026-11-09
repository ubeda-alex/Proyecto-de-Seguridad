<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class TeacherUpdateRequest extends FormRequest
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
        $teacherId = $this->input('teacher_id');

        return [
            'teacher_id'    => [
                'required',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'teacher')),
            ],
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'email'         => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($teacherId),
            ],
            'gender'        => 'required|string|max:50',
            'nationality'   => 'required|string|max:100',
            'phone'         => 'required|string|max:50',
            'address'       => 'required|string|max:255',
            'address2'      => 'nullable|string|max:255',
            'city'          => 'required|string|max:100',
            'zip'           => 'required|string|max:20',
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
