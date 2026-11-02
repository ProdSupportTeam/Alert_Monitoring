<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Route;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReportController extends Controller
{
    /**
     * On-demand PDF download of the business report for Flight & Hotel orders.
     *
     * Hit this URL any time you need a PDF - it renders and streams the PDF
     * straight back to the browser (no WhatsApp send, nothing saved to disk).
     *
     * Route:  GET /reports/business/download
     * Optional query param ?date=YYYY-MM-DD to pick a specific business day
     * (defaults to the previous full day, Asia/Kolkata).
     */
    public function downloadBusinessReport(Request $request)
    {
        $tz = 'Asia/Kolkata';

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

        $start = $day->copy()->startOfDay();
        $end   = $day->copy()->addDay()->startOfDay();
        $reportDate = $start->format('d-M-Y');

        [$summary, $grandOrders, $grandAmount] = $this->buildSummary($start, $end);
        $html      = $this->buildHtml($reportDate, $summary, $grandOrders, $grandAmount);
        $pdfOutput = $this->renderPdf($html);

        $fileName = 'business_report_' . $start->format('Y-m-d') . '.pdf';

        return response($pdfOutput, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }

    /**
     * Daily previous-day business report for Flight & Hotel orders.
     *
     * Generates a PDF of order counts and amounts for the previous business
     * day (Asia/Kolkata) and delivers it over WhatsApp as a document.
     *
     * Intended to be triggered once each morning by a cron / Windows Task
     * Scheduler that hits:  GET /reports/daily-business
     *
     * NOTE (DPDP Act 2023): this report is intentionally AGGREGATE ONLY
     * (counts + amounts). No customer name / mobile / email / device id is
     * included, so no personal data leaves the system in the report.
     */
    public function dailyBusinessReport()
    {
        //echo "hi";exit;
        $tz = 'Asia/Kolkata';

        // Previous full day window: yesterday 00:00:00 (inclusive) -> today 00:00:00 (exclusive)
        $start = Carbon::now($tz)->subDay()->startOfDay();
        $end   = Carbon::now($tz)->startOfDay();
        $reportDate = $start->format('d-M-Y');

        [$summary, $grandOrders, $grandAmount] = $this->buildSummary($start, $end);

        // Render HTML -> PDF.

        $html      = $this->buildHtml($reportDate, $summary, $grandOrders, $grandAmount);
        //echo "<pre>"; print_r($html); echo "</pre>"; exit;

        $pdfOutput = $this->renderPdf($html);

        // Save to a web-reachable path with an unguessable filename.
        // NOTE: this folder is deliberately NOT under the /reports URL prefix -
        // a real dir there would shadow the /reports/* routes (Apache serves it
        // off disk instead of routing to Lumen -> 404).
        $dir = base_path('public/report_files');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->cleanupOldReports($dir, 7); // retain 7 days, then purge

        $fileName = 'business_report_' . $start->format('Y-m-d') . '_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($dir . DIRECTORY_SEPARATOR . $fileName, $pdfOutput);

        $baseUrl   = rtrim(env('REPORT_PUBLIC_BASE_URL', env('APP_URL', '')), '/');
        $publicUrl = $baseUrl . '/report_files/' . $fileName;

        // Deliver over WhatsApp as a document to the configured recipient list.
        $recipients = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REPORT_WHATSAPP_RECIPIENTS', ''))
        )));

        $caption = "Daily Business Report ($reportDate) - Flight & Hotel\n"
            . "Total Orders: $grandOrders | Total Amount: " . number_format($grandAmount, 2);

        $delivered = [];
        foreach ($recipients as $to) {
            $delivered[] = $to;
            $this->sendWhatsappDocument($to, $publicUrl, $fileName, $caption);
        }

        return response()->json([
            'status'       => 'REPORT_GENERATED',
            'report_date'  => $reportDate,
            'grand_orders' => $grandOrders,
            'grand_amount' => round($grandAmount, 2),
            'pdf_url'      => $publicUrl,
            'recipients'   => $delivered,
        ]);
    }

    /**
     * Aggregate Flight & Hotel orders for the given window.
     *
     * Returns [summaryByService, grandOrders, grandAmount].
     * AGGREGATE ONLY - counts + amounts, no customer PII (DPDP Act 2023).
     */
    private function buildSummary(Carbon $start, Carbon $end)
    {
        $services = ['Flight', 'Hotel'];

        // order_amount is stored as varchar(25) -> CAST for a reliable SUM.
        $rows = DB::table('orders')
            ->select(
                'service_token',
                'order_status',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(CAST(order_amount AS DECIMAL(15,2))), 0) as amt')
            )
            ->whereIn('service_token', $services)
            ->where('order_date', '>=', $start)
            ->where('order_date', '<', $end)
            ->groupBy('service_token', 'order_status')
            ->get();
            //echo "<pre>"; print_r($rows); echo "</pre>"; exit;

        $summary = [];
        foreach ($services as $svc) {
            $summary[$svc] = ['statuses' => [], 'orders' => 0, 'amount' => 0.0];
        }
        foreach ($rows as $r) {
            $svc = $r->service_token;
            if (!isset($summary[$svc])) {
                continue;
            }
            $status = ($r->order_status !== null && $r->order_status !== '') ? $r->order_status : 'UNKNOWN';
            $summary[$svc]['statuses'][] = [
                'status' => $status,
                'count'  => (int) $r->cnt,
                'amount' => (float) $r->amt,
            ];
            $summary[$svc]['orders'] += (int) $r->cnt;
            $summary[$svc]['amount'] += (float) $r->amt;
        }

        $grandOrders = 0;
        $grandAmount = 0.0;
        foreach ($summary as $s) {
            $grandOrders += $s['orders'];
            $grandAmount += $s['amount'];
        }

        return [$summary, $grandOrders, $grandAmount];
    }

    /**
     * Render report HTML into raw PDF bytes via dompdf.
     */
    private function renderPdf($html)
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false); // no remote asset fetch -> no SSRF surface
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Build the report HTML consumed by dompdf.
     */
    private function buildHtml($reportDate, array $summary, $grandOrders, $grandAmount)
    {
        $generatedAt = Carbon::now('Asia/Kolkata')->format('d-M-Y H:i');

        $serviceBlocks = '';
        foreach ($summary as $service => $data) {
            $statusRows = '';
            if (empty($data['statuses'])) {
                $statusRows = '<tr><td colspan="3" style="text-align:center;color:#888;">No orders for this service</td></tr>';
            } else {
                foreach ($data['statuses'] as $s) {
                    $statusRows .= '<tr>'
                        . '<td>' . htmlspecialchars($s['status']) . '</td>'
                        . '<td style="text-align:right;">' . $s['count'] . '</td>'
                        . '<td style="text-align:right;">' . number_format($s['amount'], 2) . '</td>'
                        . '</tr>';
                }
            }

            $serviceBlocks .= '
            <h3 class="svc">' . htmlspecialchars($service) . '</h3>
            <table class="tbl">
                <thead>
                    <tr><th>Order Status</th><th style="text-align:right;">Orders</th><th style="text-align:right;">Amount</th></tr>
                </thead>
                <tbody>' . $statusRows . '</tbody>
                <tfoot>
                    <tr class="subtotal">
                        <td>Sub-total</td>
                        <td style="text-align:right;">' . $data['orders'] . '</td>
                        <td style="text-align:right;">' . number_format($data['amount'], 2) . '</td>
                    </tr>
                </tfoot>
            </table>';
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: "DejaVu Sans", sans-serif; color: #222; font-size: 12px; }
    .header { border-bottom: 3px solid #1a5276; padding-bottom: 8px; margin-bottom: 16px; }
    .header h1 { margin: 0; font-size: 18px; color: #1a5276; }
    .header .meta { font-size: 11px; color: #666; margin-top: 4px; }
    h3.svc { margin: 18px 0 6px; color: #1a5276; font-size: 14px; }
    table.tbl { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.tbl th, table.tbl td { border: 1px solid #ccc; padding: 6px 8px; }
    table.tbl thead th { background: #1a5276; color: #fff; font-size: 11px; }
    table.tbl tfoot .subtotal td { background: #eaf2f8; font-weight: bold; }
    .grand { margin-top: 20px; width: 100%; border-collapse: collapse; }
    .grand td { padding: 8px; border: 1px solid #1a5276; font-weight: bold; background: #d4e6f1; }
    .footer { margin-top: 24px; font-size: 10px; color: #999; text-align: center; }
</style>
</head>
<body>
    <div class="header">
        <h1>Daily Business Report</h1>
        <div class="meta">Services: Flight &amp; Hotel &nbsp;|&nbsp; Business Date: <strong>' . htmlspecialchars($reportDate) . '</strong> &nbsp;|&nbsp; Generated: ' . htmlspecialchars($generatedAt) . ' IST</div>
    </div>

    ' . $serviceBlocks . '

    <table class="grand">
        <tr>
            <td>GRAND TOTAL</td>
            <td style="text-align:right;">Orders: ' . $grandOrders . '</td>
            <td style="text-align:right;">Amount: ' . number_format($grandAmount, 2) . '</td>
        </tr>
    </table>

    <div class="footer">R360 Monitoring &mdash; Confidential. Aggregate figures only.</div>
</body>
</html>';
    }

    /**
     * Delete generated report PDFs older than $days days.
     */
    private function cleanupOldReports($dir, $days)
    {
        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'business_report_*.pdf') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Send a PDF as a WhatsApp document via the R360 SB-WhatsApp API.
     *
     * !! VERIFY THIS PAYLOAD against the SB-WhatsApp API documentation. !!
     * The existing template-send payload shape is reused, switching type to
     * "document" with a public link. The exact field names for document sends
     * (document.link / filename / caption) may differ on the R360 gateway.
     */
    private function sendWhatsappDocument($mobile, $documentUrl, $fileName, $caption)
    {
        $url = "https://offers-adminuat.reward360.in/sbwhatsapp/api/v1/whatsapp/sbnotification/sendMessage";

        $headers = [
            "accept: application/json",
            "authKey: " . env('WHATSAPP_AUTH_KEY'),
            "Content-Type: application/json",
        ];

        $payload = [
            "request" => [
                "messages" => [
                    [
                        "sender"        => "",
                        "to"            => $mobile,
                        "channel"       => "wa",
                        "type"          => "document",
                        "callbackDlrUrl" => "",
                        "document"      => [
                            "link"     => $documentUrl,
                            "filename" => $fileName,
                            "caption"  => $caption,
                        ],
                    ],
                ],
                "responseType" => "json",
            ],
            "order_ref_num" => "report_" . time(),
            "order_status"  => "report",
            "media_type"    => "document",
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL           => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST          => true,
            CURLOPT_HTTPHEADER    => $headers,
            CURLOPT_POSTFIELDS    => json_encode($payload),
            CURLOPT_TIMEOUT       => 15,
        ]);

        $response = curl_exec($ch);
        echo "<pre>"; print_r($response); echo "xxx"; exit;

        if (curl_errno($ch)) {
            \Log::error('WhatsApp Report Document Error: ' . curl_error($ch));
        }

        curl_close($ch);

        return $response;
    }
}
