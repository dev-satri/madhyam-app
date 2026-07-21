<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    // Note: `salary` was removed intentionally. Payroll must flow through the
    // Salary/Payroll module (salaries / payslips + LedgerService), not through
    // free-form expenses, to prevent double-counting in Net Profit.
    case Office = 'office';
    case Operations = 'operations';
    case Software = 'software';
    case Equipment = 'equipment';
    case Travel = 'travel';
    case Marketing = 'marketing';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
