<?php

namespace Tests\Unit;

use App\Models\Leave;
use App\Models\OvertimeLog;
use App\Models\Salary;
use App\Models\Setting;
use App\Models\User;
use App\Services\SalaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected SalaryCalculator $calculator;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SalaryCalculator;
        $this->member = User::create([
            'name' => 'Test Member',
            'email' => 'test@test.com',
            'password' => 'password',
            'role' => 'editor',
        ]);
        Setting::create(['base_salary_default' => 25000]);
    }

    public function test_base_salary_only(): void
    {
        $salary = $this->calculator->ensureFor($this->member, 1, 2026);

        $this->assertEquals(25000, $salary->base_salary);
        $this->assertEquals(0, $salary->overtime_pay);
        $this->assertEquals(0, $salary->bonus);
        $this->assertEquals(0, $salary->leave_deduction);
        $this->assertEquals(25000, $salary->net_salary);
    }

    public function test_unpaid_leave_deducts_daily_rate(): void
    {
        Leave::create([
            'member_id' => $this->member->id,
            'type' => 'sick',
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
            'status' => 'approved',
        ]);

        $salary = $this->calculator->ensureFor($this->member, 1, 2026);

        $dailyRate = 25000 / 30.0;
        $this->assertEquals(1, $salary->unpaid_leaves);
        $this->assertEquals(round($dailyRate, 2), $salary->leave_deduction);
        $this->assertEquals(21, $salary->total_work_days);
        $this->assertEquals(round(25000 - $dailyRate, 2), $salary->net_salary);
    }

    public function test_paid_leave_no_deduction(): void
    {
        Leave::create([
            'member_id' => $this->member->id,
            'type' => 'annual',
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
            'status' => 'approved',
        ]);

        $salary = $this->calculator->ensureFor($this->member, 1, 2026);

        $this->assertEquals(1, $salary->paid_leaves);
        $this->assertEquals(0, $salary->unpaid_leaves);
        $this->assertEquals(0, $salary->leave_deduction);
        $this->assertEquals(25000, $salary->net_salary);
    }

    public function test_overtime_pay_calculated(): void
    {
        OvertimeLog::create([
            'member_id' => $this->member->id,
            'date' => '2026-01-10',
            'hours' => 4,
            'rate' => 500,
            'approved' => true,
        ]);

        $salary = $this->calculator->ensureFor($this->member, 1, 2026);

        $this->assertEquals(2000, $salary->overtime_pay);
        $this->assertEquals(27000, $salary->net_salary);
    }

    public function test_unapproved_overtime_not_counted(): void
    {
        OvertimeLog::create([
            'member_id' => $this->member->id,
            'date' => '2026-01-10',
            'hours' => 4,
            'rate' => 500,
            'approved' => false,
        ]);

        $salary = $this->calculator->ensureFor($this->member, 1, 2026);

        $this->assertEquals(0, $salary->overtime_pay);
        $this->assertEquals(25000, $salary->net_salary);
    }

    public function test_bonus_added(): void
    {
        $salary = $this->calculator->ensureFor($this->member, 1, 2026);
        $salary->bonus = 5000;
        $this->calculator->recalc($salary);

        $this->assertEquals(5000, $salary->bonus);
        $this->assertEquals(30000, $salary->net_salary);
    }

    public function test_ensure_for_is_idempotent(): void
    {
        $s1 = $this->calculator->ensureFor($this->member, 1, 2026);
        $s2 = $this->calculator->ensureFor($this->member, 1, 2026);

        $this->assertEquals($s1->id, $s2->id);
        $this->assertEquals(1, Salary::where('member_id', $this->member->id)->where('month', 1)->where('year', 2026)->count());
    }

    public function test_work_days_never_negative(): void
    {
        // Create 25 unpaid leaves (more than 22 work days)
        Leave::create([
            'member_id' => $this->member->id,
            'type' => 'personal',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-25',
            'status' => 'approved',
        ]);

        $salary = $this->calculator->ensureFor($this->member, 1, 2026);

        $this->assertEquals(0, $salary->total_work_days);
        $this->assertGreaterThanOrEqual(0, $salary->net_salary);
    }
}
