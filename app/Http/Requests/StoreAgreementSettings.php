<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreAgreementSettings extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('superuser');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Titles are single-line headings; bodies are free-form legal text.
     * All fields are nullable — blank means "fall back to the eula.php
     * lang default" via UserAgreement::resolveEulaText().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agreement_pickup_title' => 'nullable|string|max:191',
            'agreement_pickup_body' => 'nullable|string|max:65535',
            'agreement_upgrade_title' => 'nullable|string|max:191',
            'agreement_upgrade_body' => 'nullable|string|max:65535',
            'agreement_purchase_title' => 'nullable|string|max:191',
            'agreement_purchase_body' => 'nullable|string|max:65535',
        ];
    }
}
