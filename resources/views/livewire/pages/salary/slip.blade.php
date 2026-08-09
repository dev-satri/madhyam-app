<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Salary Slip - {{ $salary->member_name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #1e293b;
            padding: 40px;
        }
        .slip {
            max-width: 600px;
            margin: 0 auto;
            border: 2px solid #1e293b;
        }
        .header {
            text-align: center;
            padding: 20px;
            border-bottom: 2px solid #1e293b;
        }
        .header h1 {
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .header p {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }
        .body {
            padding: 20px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px dashed #cbd5e1;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
        }
        .info-label {
            font-weight: bold;
        }
        .info-value {
            text-align: right;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        th,
        td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px dashed #e2e8f0;
        }
        th {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 11px;
        }
        td:last-child,
        th:last-child {
            text-align: right;
        }
        .total-row {
            font-weight: bold;
            font-size: 15px;
            border-top: 2px solid #1e293b;
            border-bottom: none;
        }
        .total-row td {
            padding-top: 12px;
            padding-bottom: 12px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            border-top: 2px solid #1e293b;
            font-size: 11px;
            color: #64748b;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="slip">
        <div class="header">
            <h1>{{ $agency->agency_name ?? 'Madhyam Agency' }}</h1>
            <p>Salary Slip</p>
        </div>
        <div class="body">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Employee:</span><span class="info-value">{{ $salary->member_name }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Month:</span
                    ><span class="info-value">{{ date('F Y', mktime(0,0,0,$salary->month,1,$salary->year)) }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Role:</span><span class="info-value">{{ $salary->member_role }}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status:</span
                    ><span class="info-value">{{ strtoupper($salary->status) }}</span>
                </div>
            </div>
            <table>
                <tr>
                    <td>Base Salary</td>
                    <td>{{ number_format($salary->base_salary, 2) }}</td>
                </tr>
                <tr>
                    <td>Overtime Pay</td>
                    <td>{{ number_format($salary->overtime_pay, 2) }}</td>
                </tr>
                <tr>
                    <td>Bonus</td>
                    <td>{{ number_format($salary->bonus, 2) }}</td>
                </tr>
                <tr>
                    <td>Leave Deduction</td>
                    <td>-{{ number_format($salary->leave_deduction, 2) }}</td>
                </tr>
                <tr>
                    <td>Paid Leaves</td>
                    <td>{{ $salary->paid_leaves }} days</td>
                </tr>
                <tr>
                    <td>Work Days</td>
                    <td>{{ $salary->total_work_days }} days</td>
                </tr>
                <tr class="total-row">
                    <td>Net Salary</td>
                    <td>{{ number_format($salary->net_salary, 2) }}</td>
                </tr>
            </table>
            <div style="font-size: 10px; color: #94a3b8; text-align: center">
                Base: {{ number_format($salary->base_salary, 2) }} + OT: {{ number_format($salary->overtime_pay, 2) }} +
                Bonus: {{ number_format($salary->bonus, 2) }} - Deduction: {{ number_format($salary->leave_deduction, 2) }} =
                Net: {{ number_format($salary->net_salary, 2) }}
            </div>
        </div>
        <div class="footer">
            <p>Generated on {{ \App\Support\NepaliDate::displayDateTime(now()) }}</p>
            <p>This is a computer-generated document.</p>
        </div>
    </div>
    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>
</html>
