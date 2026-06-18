<?php

namespace Modules\Order\App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AgentOrderCreatedDirectEmployeeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $orderNo,
        public readonly string $orderDate,
        public readonly string $orderStatusLabel,
        public readonly string $customerName,
        public readonly string $netAmountFormatted,
        public readonly string $currency,
        public readonly string $agentCode,
        public readonly string $agentName,
        public readonly string $directEmployeeName,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('[FECO] Đại lý có đơn hàng mới: '.$this->orderNo)
            ->view('order::emails.agent-order-created-direct-employee');
    }
}

