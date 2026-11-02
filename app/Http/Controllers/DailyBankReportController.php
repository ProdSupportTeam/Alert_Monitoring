<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

/**
 * Daily Bank Report - Flight & Hotel success/failure counts + percentage.
 *
 * Runs 4 queries for the previous business day, writes them into a single
 * CSV (one titled block per query), and emails the CSV as an attachment to
 * the bank recipient list.
 *
 * Trigger once each morning via cron / Windows Task Scheduler:
 *     GET /reports/daily-bank-csv
 * Optional ?date=YYYY-MM-DD to re-run for a specific business day.
 *
 * DPDP Act 2023: this report is AGGREGATE ONLY (counts + percentages).
 * Do NOT add customer name / mobile / email / PAN / device id to any of the
 * 4 queries below - no personal data must leave the system in this report.
 */
class DailyBankReportController extends Controller
{
    /**
     * Cron entrypoint: build the CSV for the previous day and email it.
     */
    public function sendDailyBankReport(Request $request)
    {
        $tz = 'Asia/Kolkata';

        // Resolve the business day (defaults to previous full day).
        $dateParam = $request->query('date');
        if ($dateParam) {
            try {
                $day = Carbon::createFromFormat('Y-m-d', $dateParam, $tz)->startOfDay();
            } catch (\Exception $e) {
                return response()->json([
                    'status'  => 'INVALID_DATE',
                    'message' => 'Use ?date=YYYY-MM-DD',
                ], 400);
            }
        } else {
            $day = Carbon::now($tz)->subDay()->startOfDay();
        }

        $start      = $day->copy()->startOfDay();
        $end        = $day->copy()->addDay()->startOfDay();
        $reportDate = $start->format('d-M-Y');

        // Run the 4 queries and build the CSV.
        $blocks   = $this->runQueries($start, $end);
        $csv      = $this->buildCsv($reportDate, $blocks);
        $fileName = 'daily_bank_report_' . $start->format('Y-m-d') . '.csv';

        // Recipients (comma-separated bank emails from .env - never hard-coded).
        $recipients = $this->recipients();
        if (empty($recipients)) {
            return response()->json([
                'status'  => 'NO_RECIPIENTS',
                'message' => 'Set REPORT_EMAIL_RECIPIENTS in .env',
            ], 500);
        }

        $subject = "Daily Report (Flight & Hotel) - $reportDate";
        $body    = "Hi,\n\nPlease find attached the daily Flight & Hotel "
                 . "success/failure report for $reportDate.\n\n"
                 . "This is an automated, aggregate-only report from R360 Monitoring.\n";

        try {
            Mail::raw($body, function ($message) use ($recipients, $subject, $csv, $fileName) {
                $message->to($recipients)
                        ->subject($subject)
                        ->attachData($csv, $fileName, ['mime' => 'text/csv']);
            });
        } catch (\Throwable $e) {
            \Log::error('Daily bank report mail failed: ' . $e->getMessage());
            return response()->json([
                'status'  => 'MAIL_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'      => 'REPORT_SENT',
            'report_date' => $reportDate,
            'recipients'  => $recipients,
            'file'        => $fileName,
        ]);
    }

    /**
     * On-demand CSV download in the browser (no email). Handy for testing.
     *     GET /reports/daily-bank-csv/download  (optional ?date=YYYY-MM-DD)
     */
    public function downloadDailyBankReport(Request $request)
    {
        $tz   = 'Asia/Kolkata';
        $day  = $request->query('date')
            ? Carbon::createFromFormat('Y-m-d', $request->query('date'), $tz)->startOfDay()
            : Carbon::now($tz)->subDay()->startOfDay();

        $start      = $day->copy()->startOfDay();
        $end        = $day->copy()->addDay()->startOfDay();
        $reportDate = $start->format('d-M-Y');

        $blocks   = $this->runQueries($start, $end);
        $csv      = $this->buildCsv($reportDate, $blocks);
        $fileName = 'daily_bank_report_' . $start->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /**
     * The 4 queries. Each returns [ 'title' => ..., 'rows' => [...] ] where
     * rows is an array of associative arrays (column => value).
     *
     * >>> PASTE YOUR SQL BELOW. <<<
     * Use the :start / :end bindings for the day window. Keep them AGGREGATE
     * ONLY (no PII) per DPDP Act 2023.
     *
     * The CSV renders whatever columns your SELECT returns - so include the
     * count + percentage columns directly in the SELECT (e.g. success_count,
     * failure_count, failure_pct).
     */
    private function runQueries(Carbon $start, Carbon $end): array
    {
        $bindings = [
            'start' => $start->toDateTimeString(),
            'end'   => $end->toDateTimeString(),
        ];

        // =================================================================
        // TEMP TEST QUERIES - to verify mail delivery only.
        // They use a fixed LAST 30 DAYS window (NOT :start/:end) so the CSV
        // has data today regardless of yesterday's volume. Replace all four
        // with your real queries later. MySQL '=' is case-insensitive, so
        // 'Flight' also matches 'flight'. Aggregate only (DPDP Act 2023).
        // =================================================================

        // ---- Query 1: Flight success/failure counts + percentage -----------
        $q1 = "
            SELECT
                SUM(order_status = 'Success')                                   AS success_count,
                SUM(order_status = 'Failed')                                    AS failure_count,
                COUNT(*)                                                        AS total_orders,
                ROUND(SUM(order_status = 'Success') / NULLIF(COUNT(*),0) * 100, 2) AS success_pct,
                ROUND(SUM(order_status = 'Failed')  / NULLIF(COUNT(*),0) * 100, 2) AS failure_pct
            FROM orders
            WHERE service_token = 'Flight'
              AND order_date >= (NOW() - INTERVAL 30 DAY)
        ";

        // ---- Query 2: Hotel success/failure counts + percentage ------------
        $q2 = "
            SELECT
                SUM(order_status = 'Success')                                   AS success_count,
                SUM(order_status = 'Failed')                                    AS failure_count,
                COUNT(*)                                                        AS total_orders,
                ROUND(SUM(order_status = 'Success') / NULLIF(COUNT(*),0) * 100, 2) AS success_pct,
                ROUND(SUM(order_status = 'Failed')  / NULLIF(COUNT(*),0) * 100, 2) AS failure_pct
            FROM orders
            WHERE service_token = 'Hotel'
              AND order_date >= (NOW() - INTERVAL 30 DAY)
        ";

        // ---- Query 3: Flight status breakdown (detail) ---------------------
        $q3 = "
            SELECT
                order_status,
                COUNT(*)                                                        AS cnt,
                ROUND(COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (),0) * 100, 2)       AS pct
            FROM orders
            WHERE service_token = 'Flight'
              AND order_date >= (NOW() - INTERVAL 30 DAY)
            GROUP BY order_status
            ORDER BY cnt DESC
        ";

        // ---- Query 4: Hotel status breakdown (detail) ----------------------
        $q4 = "
            SELECT
                order_status,
                COUNT(*)                                                        AS cnt,
                ROUND(COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (),0) * 100, 2)       AS pct
            FROM orders
            WHERE service_token = 'Hotel'
              AND order_date >= (NOW() - INTERVAL 30 DAY)
            GROUP BY order_status
            ORDER BY cnt DESC
        ";

        return [
            ['title' => 'Flight - Success / Failure', 'rows' => $this->select($q1, $bindings)],
            ['title' => 'Hotel - Success / Failure',  'rows' => $this->select($q2, $bindings)],
            ['title' => 'Query 3',                    'rows' => $this->select($q3, $bindings)],
            ['title' => 'Query 4',                    'rows' => $this->select($q4, $bindings)],
        ];
    }

    /**
     * Run a raw SELECT and return rows as an array of associative arrays.
     * Named bindings :start / :end are only passed if the SQL references them,
     * so placeholder queries without bindings still run.
     */
    private function select(string $sql, array $bindings): array
    {
        $pass = [];
        foreach ($bindings as $k => $v) {
            if (strpos($sql, ':' . $k) !== false) {
                $pass[$k] = $v;
            }
        }

        try {
            $rows = DB::select($sql, $pass);
        } catch (\Throwable $e) {
            \Log::error('Daily bank report query failed: ' . $e->getMessage());
            return [['error' => 'Query failed: ' . $e->getMessage()]];
        }

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * Build a single CSV string: a titled block per query, each with its own
     * header row (derived from the query's columns) and data rows.
     */
    private function buildCsv(string $reportDate, array $blocks): string
    {
        $fh = fopen('php://temp', 'r+');

        fputcsv($fh, ['Daily Bank Report - Flight & Hotel']);
        fputcsv($fh, ['Business Date', $reportDate]);
        fputcsv($fh, ['Generated (IST)', Carbon::now('Asia/Kolkata')->format('d-M-Y H:i')]);
        fputcsv($fh, []); // blank separator row

        foreach ($blocks as $block) {
            fputcsv($fh, [$block['title']]);

            $rows = $block['rows'];
            if (empty($rows)) {
                fputcsv($fh, ['No data for this period']);
            } else {
                fputcsv($fh, array_keys($rows[0])); // header from column names
                foreach ($rows as $row) {
                    fputcsv($fh, array_values($row));
                }
            }
            fputcsv($fh, []); // blank separator row
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        // UTF-8 BOM so Excel opens the CSV with correct encoding.
        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * Parse the comma-separated bank recipient list from .env.
     */
    private function recipients(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REPORT_EMAIL_RECIPIENTS', ''))
        )));
    }
}
