<?php

namespace App\Http\Controllers;

use App\Support\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Lumen\Routing\Controller;

/**
 * MySQL query results -> Excel (.xlsx), either streamed as a browser download
 * or emailed as an attachment.
 *
 * Routes:
 *     GET /reports/excel/download        stream the workbook to the browser
 *     GET /reports/excel/email           email the workbook to REPORT_EMAIL_RECIPIENTS
 * Both accept an optional ?date=YYYY-MM-DD (defaults to the previous full day).
 *
 * Writes real .xlsx via App\Support\XlsxWriter (no ext-zip / no new composer
 * dependency - see the notes in that class).
 *
 * DPDP Act 2023: the queries below are AGGREGATE ONLY (counts + percentages).
 * Do NOT add customer name / mobile / email / PAN / device id - no personal
 * data must leave the system through this export.
 */
class ExcelReportController extends Controller
{
    /**
     * Stream the workbook straight back to the browser as a download.
     */
    public function download(Request $request)
    {
        $day = $this->resolveDay($request);
        if ($day instanceof \Illuminate\Http\JsonResponse) {
            return $day;
        }

        $start = $day->copy()->startOfDay();
        $end   = $day->copy()->addDay()->startOfDay();

        $bytes    = $this->buildWorkbook($start, $end);
        $fileName = 'orders_report_' . $start->format('Y-m-d') . '.xlsx';

        return response($bytes, 200, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => 'attachment; filename="' . $fileName . '"',
            'Content-Length'         => strlen($bytes),
            'Cache-Control'          => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Cron entrypoint: build the workbook and email it as an attachment.
     */
    public function email(Request $request)
    {
        $day = $this->resolveDay($request);
        if ($day instanceof \Illuminate\Http\JsonResponse) {
            return $day;
        }

        $start      = $day->copy()->startOfDay();
        $end        = $day->copy()->addDay()->startOfDay();
        $reportDate = $start->format('d-M-Y');

        $recipients = $this->recipients();
        if (empty($recipients)) {
            return response()->json([
                'status'  => 'NO_RECIPIENTS',
                'message' => 'Set REPORT_EMAIL_RECIPIENTS in .env',
            ], 500);
        }

        $bytes    = $this->buildWorkbook($start, $end);
        $fileName = 'orders_report_' . $start->format('Y-m-d') . '.xlsx';

        $subject = "Daily Report (Flight & Hotel) - $reportDate";
        $body    = "Hi,\n\nPlease find attached the daily Flight & Hotel report for "
                 . "$reportDate as an Excel workbook.\n\n"
                 . "This is an automated, aggregate-only report from R360 Monitoring.\n";

        try {
            Mail::raw($body, function ($message) use ($recipients, $subject, $bytes, $fileName) {
                $message->to($recipients)
                        ->subject($subject)
                        ->attachData($bytes, $fileName, [
                            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ]);
            });
        } catch (\Throwable $e) {
            \Log::error('Excel report mail failed: ' . $e->getMessage());
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
     * Run the queries and lay them out into worksheets.
     *
     * One sheet per query here; swap to addBlocksSheet() if you would rather
     * stack every query onto a single tab (same layout as the existing CSV).
     */
    private function buildWorkbook(Carbon $start, Carbon $end): string
    {
        $reportDate = $start->format('d-M-Y');
        $meta       = [
            'subtitle' => [
                ['Business Date', $reportDate],
                ['Generated (IST)', Carbon::now('Asia/Kolkata')->format('d-M-Y H:i')],
            ],
        ];

        $xlsx = new XlsxWriter();

        foreach ($this->runQueries($start, $end) as $block) {
            $xlsx->addQuerySheet(
                $block['sheet'],
                $block['rows'],
                $meta + ['title' => $block['title']]
            );
        }

        // Single-tab alternative - comment out the loop above and use this:
        // $xlsx->addBlocksSheet('Report', $this->runQueries($start, $end), $meta + [
        //     'title' => 'Daily Report - Flight & Hotel',
        // ]);

        return $xlsx->output();
    }

    /**
     * The queries feeding the workbook.
     *
     * >>> PASTE YOUR SQL HERE. <<<
     * Use the :start / :end named bindings for the day window - never string-
     * concatenate request input into SQL (PCI DSS v4.0.1 Req 6.2.4 - injection
     * defences). Whatever columns the SELECT returns become the sheet columns,
     * so put the counts / percentages in the SELECT itself.
     *
     * @return array<int, array{sheet:string, title:string, rows:array}>
     */
    private function runQueries(Carbon $start, Carbon $end): array
    {
        $bindings = [
            'start' => $start->toDateTimeString(),
            'end'   => $end->toDateTimeString(),
        ];

        // ---- Query 1: Flight success/failure counts + percentage -----------
        $q1 = "
            SELECT
                SUM(order_status = 'Successful')                                     AS success_count,
                SUM(order_status = 'Failed')                                      AS failure_count,
                COUNT(*)                                                          AS total_orders,
                ROUND(SUM(order_status = 'Successful') / NULLIF(COUNT(*),0) * 100, 2) AS success_pct,
                ROUND(SUM(order_status = 'Failed')  / NULLIF(COUNT(*),0) * 100, 2) AS failure_pct
            FROM orders
            WHERE service_token = 'Flight'
              AND order_date >= :start
              AND order_date <  :end
        ";

        // ---- Query 2: Hotel success/failure counts + percentage ------------
        $q2 = "
            SELECT
                SUM(order_status = 'Successful')                                     AS success_count,
                SUM(order_status = 'Failed')                                      AS failure_count,
                COUNT(*)                                                          AS total_orders,
                ROUND(SUM(order_status = 'Successful') / NULLIF(COUNT(*),0) * 100, 2) AS success_pct,
                ROUND(SUM(order_status = 'Failed')  / NULLIF(COUNT(*),0) * 100, 2) AS failure_pct
            FROM orders
            WHERE service_token = 'Hotel'
              AND order_date >= :start
              AND order_date <  :end
        ";

        // ---- Query 3: status breakdown per service (detail) ----------------
        $q3 = "
            SELECT
                service_token,
                order_status,
                COUNT(*)                                                          AS cnt,
                COALESCE(SUM(CAST(order_amount AS DECIMAL(15,2))), 0)              AS amount,
                ROUND(COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (),0) * 100, 2)         AS pct
            FROM orders
            WHERE service_token IN ('Flight', 'Hotel')
              AND order_date >= :start
              AND order_date <  :end
            GROUP BY service_token, order_status
            ORDER BY service_token, cnt DESC
        ";

        return [
            [
                'sheet' => 'Flight Summary',
                'title' => 'Flight - Success / Failure',
                'rows'  => $this->select($q1, $bindings),
            ],
            [
                'sheet' => 'Hotel Summary',
                'title' => 'Hotel - Success / Failure',
                'rows'  => $this->select($q2, $bindings),
            ],
            [
                'sheet' => 'Status Breakdown',
                'title' => 'Status Breakdown - Flight & Hotel',
                'rows'  => $this->select($q3, $bindings),
            ],
        ];
    }

    /**
     * Run a raw SELECT and return rows as an array of associative arrays.
     * Only the bindings the SQL actually references are passed, so placeholder
     * queries without :start / :end still run.
     */
    private function select(string $sql, array $bindings): array
    {
        $pass = [];
        foreach ($bindings as $key => $value) {
            if (strpos($sql, ':' . $key) !== false) {
                $pass[$key] = $value;
            }
        }

        try {
            $rows = DB::select($sql, $pass);
        } catch (\Throwable $e) {
            // Log the detail, hand back a short marker - the DB error text must
            // not travel out in the workbook to a bank recipient.
            \Log::error('Excel report query failed: ' . $e->getMessage());
            return [['error' => 'Query failed - see application log']];
        }

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /**
     * Resolve the business day from ?date=YYYY-MM-DD, defaulting to the
     * previous full day (Asia/Kolkata). Returns a JsonResponse on bad input.
     */
    private function resolveDay(Request $request)
    {
        $tz        = 'Asia/Kolkata';
        $dateParam = $request->query('date');

        if (!$dateParam) {
            return Carbon::now($tz)->subDay()->startOfDay();
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $dateParam, $tz);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'INVALID_DATE',
                'message' => 'Use ?date=YYYY-MM-DD',
            ], 400);
        }

        if ($day === false || $day->format('Y-m-d') !== $dateParam) {
            return response()->json([
                'status'  => 'INVALID_DATE',
                'message' => 'Use ?date=YYYY-MM-DD',
            ], 400);
        }

        return $day->startOfDay();
    }

    /**
     * Comma-separated recipient list from .env (never hard-coded).
     */
    private function recipients(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REPORT_EMAIL_RECIPIENTS', ''))
        )));
    }
}
