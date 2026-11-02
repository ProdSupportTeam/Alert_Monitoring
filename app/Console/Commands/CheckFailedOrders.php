<?php

namespace App\Console\Commands;

use App\Http\Controllers\OrderController;
use Illuminate\Console\Command;

class CheckFailedOrders extends Command
{
    /**
     * @var string
     */
    protected $signature = 'alerts:failed-orders';

    /**
     * @var string
     */
    protected $description = 'Scan the last 15 minutes of orders and raise WhatsApp alerts for failure spikes or silent partners.';

    public function handle()
    {
        $payload = json_decode((new OrderController())->failedLast15Minutes()->getContent(), true);

        $this->line('[' . date('Y-m-d H:i:s') . '] ' . ($payload['status'] ?? 'UNKNOWN'));

        if (! empty($payload['message'])) {
            $this->line($payload['message']);
        }

        return 0;
    }
}
