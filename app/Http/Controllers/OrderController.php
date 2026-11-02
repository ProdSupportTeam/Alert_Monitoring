<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller;

class OrderController extends Controller
{
    /**
     * Failure-rate thresholds per service and partner.
     *
     * percent      - at or above this, severity is WARNING (alerts the team).
     * high_percent - at or above this, severity is HIGH (escalates to the director).
     * CRITICAL is a global boundary, see self::CRITICAL_PERCENT.
     *
     * min_failures is kept at ceil(min_orders * percent / 100). Setting it any
     * higher makes WARNING unreachable at low volume, so the first alert for a
     * partner would skip the team and page the director or VP directly.
     */
    private const THRESHOLDS = [
        'flight' => [
            'CLEARTRIP'  => ['min_orders' => 10, 'min_failures' => 3, 'percent' => 30, 'high_percent' => 40],
            'EASEMYTRIP' => ['min_orders' => 10, 'min_failures' => 3, 'percent' => 25, 'high_percent' => 38],
            'GoIbibo'    => ['min_orders' => 15, 'min_failures' => 6, 'percent' => 35, 'high_percent' => 43],
            'Yatra'      => ['min_orders' => 8,  'min_failures' => 3, 'percent' => 30, 'high_percent' => 40],
        ],
        'hotels' => [
            'Cleartrip'  => ['min_orders' => 8,  'min_failures' => 3, 'percent' => 30, 'high_percent' => 40],
            'Makemytrip' => ['min_orders' => 12, 'min_failures' => 3, 'percent' => 25, 'high_percent' => 38],
            'Yatra'      => ['min_orders' => 8,  'min_failures' => 3, 'percent' => 30, 'high_percent' => 40],
        ],
    ];

    /**
     * Near-total outage override. A partner that fails almost everything is an
     * incident even when volume is below min_orders, so these bypass that gate.
     */
    private const OUTAGE_PERCENT = 90;
    private const OUTAGE_MIN_FAILURES = 5;

    /**
     * Zero-traffic detection. A silent partner only matters if it would normally
     * be busy, so "zero" is judged against the same clock-time window on the
     * previous BASELINE_DAYS days (median), not against a fixed number.
     */
    private const BASELINE_DAYS = 7;
    private const MIN_BASELINE_ORDERS = 3;

    /**
     * Failure percentage at which an alert is escalated to CRITICAL.
     */
    private const CRITICAL_PERCENT = 50;

    /**
     * Window (in minutes) scanned for failed orders.
     */
    private const WINDOW_MINUTES = 15;

    /**
     * Escalation ladder: each severity notifies its own tier plus every tier below it.
     * Values are the .env keys holding comma-separated WhatsApp numbers per tier.
     */
    private const ESCALATION = [
        'WARNING'  => ['ALERT_WHATSAPP_TEAM'],
        'HIGH'     => ['ALERT_WHATSAPP_TEAM', 'ALERT_WHATSAPP_DIRECTOR'],
        'CRITICAL' => ['ALERT_WHATSAPP_TEAM', 'ALERT_WHATSAPP_DIRECTOR', 'ALERT_WHATSAPP_VP'],
    ];

    /**
     * Per-recipient gateway outcomes for the current request, surfaced in the
     * response so a rejected send is not mistaken for a delivered one.
     *
     * @var array
     */
    private $deliveries = [];

    public function failedLast15Minutes()
    {
        $now = Carbon::now('Asia/Kolkata');
        $last15Min = $now->copy()->subMinutes(self::WINDOW_MINUTES);

        $alertMessages = [];
        $silentPartners = [];
        $partnerCount = 0;

        foreach (self::THRESHOLDS as $service => $partners) {
            foreach ($partners as $partner => $threshold) {
                $partnerCount++;

                $totalOrders = DB::table('orders')
                    ->where('service_token', $service)
                    ->where('partner_token', $partner)
                    ->where('order_date', '>=', $last15Min)
                    ->count();

                // No orders at all - there is no failure rate to compute, but a
                // partner that is normally busy right now has gone silent.
                if ($totalOrders === 0) {
                    $expected = $this->expectedOrders($service, $partner, $last15Min, $now);

                    if ($expected >= self::MIN_BASELINE_ORDERS) {
                        $silentPartners[] = [
                            'service'  => $service,
                            'partner'  => $partner,
                            'expected' => $expected,
                        ];
                    }

                    continue;
                }

                $failedOrders = DB::table('orders')
                    ->where('service_token', $service)
                    ->where('partner_token', $partner)
                    ->where('pg_status', 'Captured')
                    ->where('order_status', 'Failed')
                    ->where('order_date', '>=', $last15Min)
                    ->count();

                $failurePercent = ($failedOrders / $totalOrders) * 100;

                if ($failurePercent >= self::CRITICAL_PERCENT) {
                    $severity = 'CRITICAL';
                } elseif ($failurePercent >= $threshold['high_percent']) {
                    $severity = 'HIGH';
                } elseif ($failurePercent >= $threshold['percent']) {
                    $severity = 'WARNING';
                } else {
                    $severity = 'NORMAL';
                }

                $meetsThreshold = $totalOrders >= $threshold['min_orders']
                    && $failedOrders >= $threshold['min_failures']
                    && $failurePercent >= $threshold['percent'];

                // A near-total outage alerts even below min_orders.
                $isOutage = $failedOrders >= self::OUTAGE_MIN_FAILURES
                    && $failurePercent >= self::OUTAGE_PERCENT;

                if (! $meetsThreshold && ! $isOutage) {
                    continue;
                }

                $alertMessages[] = sprintf(
                    'Hey dude,please check there is an alert with [%s] status with failures in %s - %s Total: %d Failed: %d Failure %%: %s%%',
                    $severity,
                    $service,
                    $partner,
                    $totalOrders,
                    $failedOrders,
                    round($failurePercent, 2)
                );

                $this->dispatchAlert($severity, $this->templateBody(
                    $service,
                    $partner,
                    $totalOrders,
                    $failedOrders,
                    round($failurePercent, 2) . '%'
                ));
            }
        }

        // Every partner silent at once points at the platform, not a partner.
        if ($silentPartners && count($silentPartners) === $partnerCount) {
            $expected = array_sum(array_column($silentPartners, 'expected'));

            $alertMessages[] = sprintf(
                '[CRITICAL] NO TRAFFIC across all %d partners in the last %d minutes '
                . '(normally ~%d orders). Possible platform-wide outage.',
                $partnerCount,
                self::WINDOW_MINUTES,
                $expected
            );

            $this->dispatchAlert('CRITICAL', $this->templateBody(
                'ALL SERVICES',
                'ALL PARTNERS',
                '0',
                'NO TRAFFIC',
                "expected ~$expected"
            ));
        } elseif ($silentPartners) {
            foreach ($silentPartners as $silent) {
                $alertMessages[] = sprintf(
                    '[HIGH] NO TRAFFIC in %s - %s for the last %d minutes (normally ~%d orders).',
                    $silent['service'],
                    $silent['partner'],
                    self::WINDOW_MINUTES,
                    $silent['expected']
                );

                $this->dispatchAlert('HIGH', $this->templateBody(
                    $silent['service'],
                    $silent['partner'],
                    '0',
                    'NO TRAFFIC',
                    'expected ~' . $silent['expected']
                ));
            }
        }

        // Low wallet balance is always treated as CRITICAL.
        $lowWallet = DB::table('orders')
            ->where('partner_error_description', 'Low wallet balance')
            ->where('order_date', '>=', $last15Min)
            ->exists();

        if ($lowWallet) {
            $alertMessages[] = '[CRITICAL] Cleartrip Low Wallet Issue!';
        }

        if (! empty($alertMessages)) {
            return response()->json([
                'status'     => 'ALERT_TRIGGERED',
                'message'    => implode("\n\n", $alertMessages),
                'deliveries' => $this->deliveries,
            ]);
        }

        return response()->json([
            'status' => 'OK',
        ]);
    }

    /**
     * Five positional placeholders expected by the critical_monitoring_alert template.
     */
    private function templateBody($service, $partner, $total, $failed, $rate)
    {
        return [
            ['type' => 'text', 'text' => (string) $service],
            ['type' => 'text', 'text' => (string) $partner],
            ['type' => 'text', 'text' => (string) $total],
            ['type' => 'text', 'text' => (string) $failed],
            ['type' => 'text', 'text' => (string) $rate],
        ];
    }

    /**
     * Median order count for the same clock-time window over the previous
     * BASELINE_DAYS days. Median rather than average so one dead day does not
     * drag the expectation to zero and mask a real outage.
     *
     * Only called when a partner has zero orders, so normal runs are unaffected.
     */
    private function expectedOrders($service, $partner, Carbon $from, Carbon $to)
    {
        $counts = [];

        for ($day = 1; $day <= self::BASELINE_DAYS; $day++) {
            $counts[] = DB::table('orders')
                ->where('service_token', $service)
                ->where('partner_token', $partner)
                ->whereBetween('order_date', [
                    $from->copy()->subDays($day),
                    $to->copy()->subDays($day),
                ])
                ->count();
        }

        sort($counts);

        return $counts[(int) floor(count($counts) / 2)];
    }

    /**
     * Send one WhatsApp message per recipient in the severity's escalation tiers.
     * The gateway accepts a single number per call, so recipients are not batched.
     */
    private function dispatchAlert($severity, $messageData)
    {
        $recipients = $this->recipientsFor($severity);

        if (empty($recipients)) {
            Log::warning("WhatsApp alert skipped: no recipients configured for severity $severity.");

            return;
        }

        foreach ($recipients as $mobile) {
            $this->deliveries[] = ['severity' => $severity]
                + $this->sendWhatsappAlert($mobile, $messageData);
        }
    }

    /**
     * Resolve the escalation ladder for a severity into a de-duplicated number list.
     */
    private function recipientsFor($severity)
    {
        $recipients = [];

        foreach (self::ESCALATION[$severity] ?? [] as $envKey) {
            $tier = array_filter(array_map('trim', explode(',', (string) env($envKey, ''))));

            if (empty($tier)) {
                Log::warning("WhatsApp escalation tier $envKey is not configured.");
            }

            $recipients = array_merge($recipients, $tier);
        }

        return array_values(array_unique($recipients));
    }

    private function formatWhatsappMessage($severity, $service, $partner, $total, $failed, $percent)
    {
        if ($severity === 'CRITICAL') {
            return "🚨 *CRITICAL ALERT* 🚨
Service: $service
Partner: $partner

❌ Failures are HIGH!
Total Orders: $total
Failed Orders: $failed
Failure Rate: " . round($percent, 2) . "%

⚠️ Immediate action required!";
        }

        if ($severity === 'HIGH') {
            return "🔴 *HIGH ALERT*
Service: $service
Partner: $partner

Failure rate is climbing.
Total Orders: $total
Failed Orders: $failed
Failure Rate: " . round($percent, 2) . "%

📈 Escalated to the director.";
        }

        if ($severity === 'WARNING') {
            return "⚠️ *WARNING ALERT*
Service: $service
Partner: $partner

Some failures detected.
Total Orders: $total
Failed Orders: $failed
Failure Rate: " . round($percent, 2) . "%

👀 Please monitor.";
        }

        return null;
    }

    /**
     * Gateway request body for exactly one recipient.
     *
     * @param string $mobile Single number - "to" must be a scalar, never an array.
     */
    private function buildPayload($mobile, $messageData)
    {
        return [
            'request' => [
                'messages' => [
                    [
                        'sender'         => '',
                        'to'             => (string) $mobile,
                        'channel'        => 'wa',
                        'type'           => 'template',
                        'callbackDlrUrl' => '',
                        'template'       => [
                            'body'       => $messageData,
                            'templateId' => 'critical_monitoring_alert',
                            'langCode'   => 'en',
                        ],
                    ],
                ],
                'responseType' => 'json',
            ],
            'order_ref_num' => 'alert_' . time(),
            'order_status'  => 'alert',
            'media_type'    => 'text',
        ];
    }

    /**
     * @param string $mobile Single recipient number - the gateway rejects arrays.
     */
    private function sendWhatsappAlert($mobile, $messageData)
    {
        $url ='https://offers-adminuat.reward360.in/sbwhatsapp/api/v1/whatsapp/sbnotification/sendMessage';

        $headers = [
            'accept: application/json',
            'authKey: ' . env('WHATSAPP_AUTH_KEY'),
            'Content-Type: application/json',
        ];

        $payload = $this->buildPayload($mobile, $messageData);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
            // Fail fast when the network is down instead of burning the full
            // timeout per recipient - a stalled run can overlap the next cron.
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        // Mobile numbers are personal data - never write them to logs in full.
        $masked = substr((string) $mobile, 0, 2) . str_repeat('*', max(0, strlen((string) $mobile) - 6))
            . substr((string) $mobile, -4);

        if ($curlError !== null) {
            Log::error("WhatsApp send to $masked failed in transport: $curlError");

            return ['to' => $masked, 'ok' => false, 'http' => 0, 'response' => $curlError];
        }

        $ok = $httpCode >= 200 && $httpCode < 300;

        if ($ok) {
            Log::info("WhatsApp send to $masked accepted (HTTP $httpCode): $response");
        } else {
            Log::error("WhatsApp send to $masked rejected (HTTP $httpCode): $response");
        }

        return ['to' => $masked, 'ok' => $ok, 'http' => $httpCode, 'response' => $response];
    }
}
