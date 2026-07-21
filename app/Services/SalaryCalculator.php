<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\OvertimeLog;
use App\Models\Salary;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

class SalaryCalculator
{
    public function ensureFor(User $member, int $month, int $year): Salary
    {
        return Salary::firstOrCreate(
            ['member_id' => $member->id, 'month' => $month, 'year' => $year],
            $this->initialAttributes($member, $month, $year)
        );
    }

    public function recalc(Salary $salary): Salary
    {
        $member = $salary->member;
        $data = $this->calculate($member, $salary->month, $salary->year, $salary->base_salary, $salary->bonus);
        $salary->fill($data);
        $salary->save();

        return $salary;
    }

    protected function initialAttributes(User $member, int $month, int $year): array
    {
        $settings = Setting::current();

        return $this->calculate($member, $month, $year, (float) $settings->base_salary_default, 0) + [
            'status' => 'pending',
        ];
    }

    protected function calculate(User $member, int $month, int $year, float $base, float $bonus): array
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $ot = OvertimeLog::where('member_id', $member->id)
            ->whereBetween('date', [$start, $end])
            ->where('approved', true)
            ->get();
        $otPay = $ot->sum(fn ($o) => (float) $o->hours * (float) $o->rate);

        $leaves = Leave::where('member_id', $member->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        $paid = 0;
        $unpaid = 0;
        foreach ($leaves as $leave) {
            $days = (int) $leave->start_date->diffInDays($leave->end_date) + 1;
            if (in_array($leave->type, ['annual', 'casual'], true)) {
                $paid += $days;
            } else {
                $unpaid += $days;
            }
        }

        $daily = $base / 30.0;
        $deduction = round($unpaid * $daily, 2);
        $workDays = max(0, 22 - $unpaid);
        $net = round($base + $otPay + $bonus - $deduction, 2);

        return [
            'base_salary' => $base,
            'overtime_pay' => round($otPay, 2),
            'bonus' => $bonus,
            'leave_deduction' => $deduction,
            'paid_leaves' => $paid,
            'unpaid_leaves' => $unpaid,
            'total_work_days' => $workDays,
            'net_salary' => $net,
        ];
    }
}
