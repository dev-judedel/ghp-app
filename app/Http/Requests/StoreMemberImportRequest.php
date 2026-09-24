<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            // CSV only, per spec — no xls/xlsx accepted. 5MB is generous for
            // a CSV of member rows; MemberCsvImportService also caps at
            // 5000 data rows regardless of file size.
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'csv_file.mimes' => 'Please upload a .csv file — Excel/XLSX files aren\'t supported for bulk import.',
        ];
    }
}
