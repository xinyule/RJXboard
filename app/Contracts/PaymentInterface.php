<?php

namespace App\Contracts;

interface PaymentInterface
{
    public function form(): array;
    public function pay($order): array;
    /**
     * Verify a gateway callback.
     *
     * @return array|string|false Array with trade_no and callback_no on
     *   success, optionally amount_cents (integer cents actually paid) for
     *   the controller's amount check; a plain string acknowledgement for
     *   a pending (not yet paid) notification; false when verification fails.
     *   A plugin MUST NOT return a success array for an unpaid, expired or
     *   failed payment: check the gateway's payment status / event type
     *   before returning, not just the signature.
     */
    public function notify($params);
}
