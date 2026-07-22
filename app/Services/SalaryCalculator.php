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
        $settings = Setting::current();
        $maxPaidLeaves = (int) $settings->paid_leaves_per_year;
        $workDaysPerMonth = (int) $settings->working_days_per_month;
        $divisor = (float) $settings->daily_wage_divisor;

        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();
        $yearStart = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $yearEnd = Carbon::createFromDate($year, 12, 31)->endOfYear();

        $ot = OvertimeLog::where('member_id', $member->id)
            ->whereBetween('date', [$start, $end])
            ->where('approved', true)
            ->get();
        $otPay = $ot->sum(fn ($o) => (float) $o->hours * (float) $o->rate);

        // All approved leaves for the member in this year (to count cumulative paid leaves)
        $yearLeaves = Leave::where('member_id', $member->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $yearEnd)
            ->whereDate('end_date', '>=', $yearStart)
            ->get();

        $totalPaidYear = 0;
        foreach ($yearLeaves as $yl) {
            $days = (int) $yl->start_date->diffInDays($yl->end_date) + 1;
            if (in_array($yl->type, ['annual', 'casual'], true)) {
                $totalPaidYear += $days;
            }
        }

        // Current month leaves
        $leaves = Leave::where('member_id', $member->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        // Calculate how many paid leaves were already used BEFORE this month
        $priorYearStart = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $priorMonthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $priorLeaves = Leave::where('member_id', $member->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '>=', $priorYearStart)
            ->whereDate('start_date', '<', $priorMonthStart)
            ->whereDate('end_date', '>=', $priorYearStart)
            ->get();

        $paidUsedPrior = 0;
        foreach ($priorLeaves as $pl) {
            if (in_array($pl->type, ['annual', 'casual'], true)) {
                $clampedStart = max($pl->start_date, $priorYearStart);
                $clampedEnd = min($pl->end_date, $priorMonthStart->copy()->subDay());
                if ($clampedEnd->gte($clampedStart)) {
                    $paidUsedPrior += (int) $clampedStart->diffInDays($clampedEnd) + 1;
                }
            }
        }

        $remainingPaidQuota = max(0, $maxPaidLeaves - $paidUsedPrior);

        $paid = 0;
        $unpaid = 0;
        foreach ($leaves as $leave) {
            $days = (int) $leave->start_date->diffInDays($leave->end_date) + 1;
            if (in_array($leave->type, ['annual', 'casual'], true)) {
                $allocatable = min($days, $remainingPaidQuota);
                $paid += $allocatable;
                $overflow = $days - $allocatable;
                if ($overflow > 0) {
                    $unpaid += $overflow;
                }
                $remainingPaidQuota = max(0, $remainingPaidQuota - $allocatable);
            } else {
                $unpaid += $days;
            }
        }

        $daily = $base / $divisor;
        $deduction = round($unpaid * $daily, 2);
        $workDays = max(0, $workDaysPerMonth - $unpaid);
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
