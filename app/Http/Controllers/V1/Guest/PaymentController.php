<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            // Fix (v1.0.1): a plugin may return a plain string acknowledgement
            // (CoinPayments returns 'IPN OK: pending' for unpaid IPNs). It must
            // not reach handle() as an array: $verify['trade_no'] on a string
            // throws TypeError on PHP 8, which catch (\Exception) cannot catch.
            if (is_string($verify)) {
                return $verify;
            }
            if (!is_array($verify) || !isset($verify['trade_no'], $verify['callback_no'])) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'], $verify['amount_cents'] ?? null)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo, $amountCents = null)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        // Fix (v1.0.1): second line of defence on amount. The expected amount
        // is the stored total plus the handling fee, because checkout passes
        // total + handling to the gateway (see OrderController::checkout).
        // Plugins that cannot report an amount return null and skip this
        // check; their status gate is then the only defence, by design.
        if ($amountCents !== null && is_numeric($amountCents)) {
            $expected = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
            if ((int) $amountCents !== $expected) {
                Log::error('Payment amount mismatch', [
                    'trade_no' => $tradeNo,
                    'expected_cents' => $expected,
                    'callback_cents' => (int) $amountCents,
                ]);
                return false;
            }
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }
}
