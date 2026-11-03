<?php

namespace App\Support;

use App\Enums\FaultCategory;
use App\Enums\FaultSeverity;
use App\Enums\FaultStatus;
use Illuminate\Validation\Rule;

final class FaultDeclarationInput
{
    public static function rules(bool $staff = false): array
    {
        return [
            'fault_declaration_state' => ['sometimes', 'required', Rule::in(['unknown', 'none', 'has_faults'])],
            'faults' => ['sometimes', 'array', 'list', 'max:100'],
            'faults.*' => ['required', 'array:id,category,title,description,severity,status,affects_drivability,is_safety_critical,disclosed_to_buyer'],
            'faults.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'faults.*.category' => ['required', Rule::in(FaultCategory::values())],
            'faults.*.title' => ['required', 'string', 'max:180'],
            'faults.*.description' => ['nullable', 'string', 'max:5000'],
            'faults.*.severity' => ['sometimes', Rule::in(FaultSeverity::values())],
            'faults.*.status' => ['sometimes', Rule::in($staff ? FaultStatus::values() : ['declared', 'repaired', 'wont_fix'])],
            'faults.*.affects_drivability' => ['sometimes', 'boolean'],
            'faults.*.is_safety_critical' => ['sometimes', 'boolean'],
            // This form is a declaration to the buyer, not the internal diagnostic editor.
            'faults.*.disclosed_to_buyer' => ['sometimes', $staff ? 'boolean' : 'accepted'],
            'fault_declared_at' => ['prohibited'], 'fault_declaration' => ['prohibited'],
            'fault_declaration_editable' => ['prohibited'],
        ];
    }
}
