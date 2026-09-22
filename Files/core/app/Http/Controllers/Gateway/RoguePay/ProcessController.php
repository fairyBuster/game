<?php

namespace App\Http\Controllers\Gateway\RoguePay;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Gateway\PaymentController;
use App\Lib\CurlRequest;
use App\Models\AdminNotification;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

class ProcessController extends Controller {

    const ALIAS = 'RoguePay';

    /*
     * OST Gateway — pay-in (deposit)
     *
     * Creates a payment request via POST /v5/gateway/charge/create
     * (https://api.nextcdn.online, direct gateway — method is REQUIRED,
     * no cashier mode) and sends the customer straight to the returned
     * `pay_url` (PG_URL). MGM wallet charges (/gateway/mgm/payin) are
     * REDIRECT flow too — QR / e-wallet pages are hosted by MGM, the
     * site only forwards the customer to their cashier link.
     *
     * The internal gateway alias stays "RoguePay" (routes, deposits
     * and admin config all reference it), only the platform behind it
     * changed: api.roguecdn.online (JAYAPAY) -> api.nextcdn.online (OST).
     *
     * Payout (disbursement) still runs on the legacy roguecdn platform
     * (JAYAPAY payout endpoints) — override with the `payout_base_url`
     * gateway parameter once OST supports disbursement too. Payout
     * credentials can be set separately via `payout_api_key` /
     * `payout_secret_key` when the OST merchant uses different keys.
     */
    public static function process($deposit) {
        $gate   = $deposit->gatewayCurrency();
        $param  = json_decode($gate->gateway_parameter);
        $user   = User::find($deposit->user_id);
        $method = @$param->default_method ?: 'QRIS';
        $direct = self::directMode($param);

        // A pending platform order already exists for this trx (page reload)
        // — send the customer back to its pay_url instead of creating a
        // duplicate charge.
        $stored = json_decode((string) @$deposit->detail);
        $reuse  = $deposit->btc_wallet && $direct && @$stored->status == 'pending'
               && @$stored->pay_url && @$stored->expires_at
               && strtotime(@$stored->expires_at) > time();
        if ($reuse) {
            $send = [
                'redirect'     => true,
                'redirect_url' => $stored->pay_url,
            ];
            return json_encode($send);
        }

        // Create the charge on the platform
        $response = self::apiRequest('POST', self::chargePath($param), [
            'amount'         => (int) round($deposit->final_amount),
            'method'         => $method,
            'description'    => 'Deposit to ' . gs('site_name'),
            'customer_name'  => $user->fullname ?: $user->username,
            'customer_email' => $user->email,
            'customer_phone' => $user->mobile ?: null,
            'merchant_ref'   => $deposit->trx,
            'return_url'     => $deposit->success_url ?: route('user.deposit.history'),
            'expiry_period'  => self::orderTtl($param),
        ], $param);

        if (!@$response->success) {
            $send['error']   = true;
            $send['message'] = self::errorText($response, 'Unable to create payment. Please try again later.');
            return json_encode($send);
        }

        // Keep the platform ref_id for webhook matching & status sync
        $deposit->btc_wallet = @$response->data->ref_id;
        $deposit->detail     = json_encode($response->data);
        $deposit->save();

        // Direct mode — send the customer straight to the platform cashier.
        // Both OST (PG_URL) and MGM (REDIRECT flow) charges are hosted by
        // the platform; pay_data carries no scannable payload.
        if ($direct) {
            $send = [
                'redirect'     => true,
                'redirect_url' => @$response->data->pay_url,
            ];
            return json_encode($send);
        }

        // Legacy cashier flow (direct mode off) — render the payment page
        $send                 = (array) $response->data;
        $send['view']         = 'user.payment.RoguePay';
        $send['method_label'] = self::methodLabel(@$response->data->method);
        return json_encode($send);
    }

    /*
     * Best error text from a platform response — FastAPI errors arrive
     * as {"detail": "..."} while platform JSON errors use "message".
     */
    private static function errorText($response, $fallback) {
        if (!is_object($response) && !is_array($response)) {
            return $fallback;
        }
        $text = is_object($response)
            ? (@$response->message ?: @$response->detail)
            : (@$response['message'] ?: @$response['detail']);
        return trim((string) $text) ?: $fallback;
    }

    /*
     * Webhook callback (POST) + status sync (GET via cron)
     *
     * POST: verifies the HMAC-SHA256 signature then matches the
     * merchant_ref against the deposit / withdrawal.
     * GET: syncs pending transactions with the platform (cron job URL).
     */
    public function ipn(Request $request) {
        $gateway = Gateway::where('alias', self::ALIAS)->first();
        if (!$gateway) {
            return response('Gateway not found', 404);
        }

        $rawBody = $request->getContent();

        // Cron / status sync request
        if ($request->isMethod('get') || !$rawBody) {
            return $this->cron();
        }

        $payload = json_decode($rawBody);
        if (!$payload) {
            return response('Invalid payload', 400);
        }

        $param = self::credentials();
        if (!self::verifyCallback($request, $payload, trim(@$param->secret_key))) {
            return response('Invalid signature', 401);
        }

        $data        = @$payload->data ?: $payload;
        $event       = @$data->event ?: @$payload->event;
        $merchantRef = @$data->merchant_ref;

        if (!$merchantRef) {
            return response('OK', 200);
        }

        if (str_starts_with($event, 'payment.')) {
            $deposit = Deposit::where('trx', $merchantRef)->whereIn('status', [Status::PAYMENT_INITIATE, Status::PAYMENT_PENDING])->first();
            if ($deposit) {
                $this->handlePaymentEvent($deposit, $event);
            }
        } elseif (str_starts_with($event, 'disbursement.') || str_starts_with($event, 'withdraw.')) {
            $withdraw = Withdrawal::where('trx', $merchantRef)->where('status', Status::PAYMENT_PENDING)->whereNotNull('pg_ref')->first();
            if ($withdraw) {
                $this->handleWithdrawEvent($withdraw, $event, $data);
            }
        }

        return response('OK', 200);
    }

    /*
     * Submit a payout to the platform (pay-out)
     *
     * Used by the admin withdraw approval flow. Returns
     * ['success' => true, 'ref_id' => ...] or
     * ['success' => false, 'fallback' => true, 'message' => ...]
     * when the withdraw method is not configured for auto payout.
     */
    public static function payout($withdraw) {
        $gateway = Gateway::where('alias', self::ALIAS)->first();
        if (!$gateway || !$gateway->status) {
            return ['success' => false, 'message' => 'OST gateway is disabled'];
        }

        $param = self::credentials();
        if (empty(trim(@$param->api_key)) || empty(trim(@$param->secret_key))) {
            return ['success' => false, 'message' => 'OST API credentials are not configured yet'];
        }

        // Already submitted — do not create a duplicate payout
        if ($withdraw->pg_ref) {
            return ['success' => true, 'ref_id' => $withdraw->pg_ref];
        }

        $destination = self::destinationInfo($withdraw->withdraw_information);
        if (!$destination) {
            return ['success' => false, 'fallback' => true, 'message' => 'Withdraw method is not configured for OST payout (fields: Account Number, Bank Code, Account Holder Name)'];
        }

        $payoutParam = self::payoutParam($param);
        $response = self::apiRequest('POST', '/merchant/withdraw/create', [
            'amount'              => (int) round($withdraw->final_amount),
            'destination_account' => $destination['account_number'],
            'bank_code'           => $destination['bank_code'],
            'account_holder_name' => $destination['account_holder_name'],
            'merchant_ref'        => $withdraw->trx,
        ], $payoutParam, self::payoutBase($param));

        if (!@$response->success) {
            return ['success' => false, 'message' => @$response->message ?: 'Unable to submit payout'];
        }

        return ['success' => true, 'ref_id' => @$response->data->ref_id];
    }

    /*
     * Current balance of the merchant account
     *
     * Tries the pay-in platform first (api.nextcdn.online), then the
     * legacy payout platform when a separate payout_base_url is set.
     */
    public static function balance() {
        $param = self::credentials();

        $lastError = 'Unable to fetch balance';

        // Pay-in platform balance (api.nextcdn.online)
        $response = self::apiRequest('GET', '/merchant/balance/balance', null, $param, trim(@$param->base_url ?: ''));
        if (@$response->success) {
            return ['success' => true, 'data' => $response->data];
        }
        $lastError = @$response->message ?: $lastError;

        // Legacy payout platform balance (separate credentials allowed)
        $payoutBase = self::payoutBase($param);
        if ($payoutBase && $payoutBase != trim(@$param->base_url ?: '')) {
            $response = self::apiRequest('GET', '/merchant/balance/balance', null, self::payoutParam($param), $payoutBase);
            if (@$response->success) {
                return ['success' => true, 'data' => $response->data];
            }
            $lastError = @$response->message ?: $lastError;
        }

        return ['success' => false, 'message' => $lastError];
    }

    /*
     * User-side status check (polled by the payment page)
     *
     * Serves every gateway in this OST family (RoguePay + MGM wallet
     * methods). MGM charges have no public status endpoint — the platform
     * pushes the final state via webhook, which updates the deposit row.
     */
    public function checkStatus(Request $request) {
        $request->validate(['trx' => 'required']);

        $deposit = Deposit::with('gateway')->where('trx', $request->trx)->where('user_id', auth()->id())->first();
        if (!$deposit || !$deposit->gateway || !self::isOstFamily($deposit->gateway->alias) || !$deposit->btc_wallet) {
            return response()->json(['success' => false, 'message' => 'Invalid deposit']);
        }

        // Webhook already finished this deposit — report the real state
        if ($deposit->status == Status::PAYMENT_SUCCESS) {
            return response()->json(['success' => true, 'status' => 'paid']);
        }
        if ($deposit->status == Status::PAYMENT_REJECT) {
            return response()->json(['success' => true, 'status' => 'failed']);
        }

        // MGM wallet: webhook only, no remote status endpoint to poll
        $gate  = $deposit->gatewayCurrency();
        $param = $gate ? json_decode($gate->gateway_parameter) : null;
        if (self::localStatusOnly($param)) {
            return response()->json(['success' => true, 'status' => 'pending']);
        }

        $response = self::getStatus($deposit->btc_wallet, $param);
        if (!@$response->success || !@$response->data) {
            return response()->json(['success' => false, 'message' => @$response->message ?: 'Unable to check payment status']);
        }

        $status = @$response->data->status;
        if ($status == 'paid') {
            PaymentController::userDataUpdate($deposit);
            return response()->json(['success' => true, 'status' => 'paid']);
        }
        if (in_array($status, ['failed', 'expired', 'cancelled'])) {
            $this->markDepositRejected($deposit, $status);
            return response()->json(['success' => true, 'status' => $status]);
        }
        return response()->json(['success' => true, 'status' => $status]);
    }

    /*
     * Sync pending deposits & withdrawals with the platform
     */
    public function cron() {
        $gateway = Gateway::where('alias', self::ALIAS)->first();
        if (!$gateway || !$gateway->status) {
            return response('Gateway disabled', 200);
        }
        $param = self::credentials();
        if (empty(trim(@$param->api_key))) {
            return response('Not configured', 200);
        }

        $deposits = Deposit::where('method_code', $gateway->code)
            ->where('status', Status::PAYMENT_INITIATE)
            ->where('btc_wallet', '!=', '')
            ->where('created_at', '>', now()->subHours(48))
            ->limit(20)
            ->get();

        foreach ($deposits as $deposit) {
            $response = self::getStatus($deposit->btc_wallet, $param);
            if (!@$response->success || !@$response->data) {
                continue;
            }
            $status = @$response->data->status;
            if ($status == 'paid') {
                PaymentController::userDataUpdate($deposit);
            } elseif (in_array($status, ['failed', 'expired', 'cancelled'])) {
                $this->markDepositRejected($deposit, $status);
            }
        }

        $withdrawals = Withdrawal::where('status', Status::PAYMENT_PENDING)->whereNotNull('pg_ref')->limit(20)->get();

        $payoutParam = self::payoutParam($param);
        foreach ($withdrawals as $withdraw) {
            $response = self::apiRequest('GET', '/merchant/withdraw/' . $withdraw->pg_ref, null, $payoutParam, self::payoutBase($param));
            if (!@$response->success || !@$response->data) {
                continue;
            }
            $status = @$response->data->status;
            if ($status == 'success') {
                $this->markWithdrawSuccess($withdraw, null);
            } elseif (in_array($status, ['failed', 'rejected'])) {
                $this->refundWithdraw($withdraw, 'Payout was not completed by the payment processor');
            }
        }

        return response('OK', 200);
    }

    /*
     * HMAC-SHA256 signed request to the platform
     * signature = HMAC_SHA256(secret, timestamp + method + path + body)
     */
    private static function apiRequest($method, $path, $body = null, $param = null, $baseUrl = null) {
        $param  = $param ?: self::credentials();
        $base   = rtrim($baseUrl ?: @$param->base_url ?: 'https://api.nextcdn.online', '/');
        return self::signAndSend($method, $path, $body, trim(@$param->api_key), trim(@$param->secret_key), $base);
    }

    /*
     * HMAC-SHA256 signed request to the platform
     * signature = HMAC_SHA256(secret, timestamp + method + path + body)
     */
    private static function signAndSend($method, $path, $body = null, $apiKey = null, $secret = null, $baseUrl = null) {
        if (!$apiKey || !$secret) {
            return (object) ['success' => false, 'message' => 'OST API credentials are not configured'];
        }

        $base   = rtrim($baseUrl ?: 'https://api.nextcdn.online', '/');
        $ts      = round(microtime(true) * 1000);
        $bodyStr = $body ? json_encode($body, JSON_UNESCAPED_SLASHES) : '';
        $sign    = hash_hmac('sha256', $ts . $method . $path . $bodyStr, $secret);

        $header = [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-API-Key: $apiKey",
            "X-Timestamp: $ts",
            "X-Signature: $sign",
        ];

        try {
            if ($method == 'GET') {
                $response = CurlRequest::curlContent($base . $path, $header);
            } else {
                $response = CurlRequest::curlPostContent($base . $path, $bodyStr, $header);
            }
        } catch (\Exception $e) {
            return (object) ['success' => false, 'message' => $e->getMessage()];
        }

        return json_decode($response);
    }

    /*
     * Verify the webhook callback signature.
     * The dispatcher signs the compact JSON body (no whitespace) while
     * posting a pretty-printed one, so we re-serialize before comparing.
     */
    private function verifyCallback(Request $request, $payload, $secret) {
        $ts  = $request->header('X-Timestamp');
        $sig = $request->header('X-Signature');
        if (!$ts || !$sig || !$secret) {
            return false;
        }
        $path = $request->getPathInfo();

        $compactBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (hash_hmac('sha256', $ts . 'POST' . $path . $compactBody, $secret) === $sig) {
            return true;
        }
        if (hash_hmac('sha256', $ts . 'POST' . $path . $request->getContent(), $secret) === $sig) {
            return true;
        }
        return false;
    }

    private function handlePaymentEvent($deposit, $event) {
        if ($event == 'payment.paid') {
            PaymentController::userDataUpdate($deposit);
            return;
        }
        if (in_array($event, ['payment.failed', 'payment.expired', 'payment.cancelled'])) {
            $this->markDepositRejected($deposit, str_replace('payment.', '', $event));
        }
    }

    private function markDepositRejected($deposit, $status) {
        if (!in_array($deposit->status, [Status::PAYMENT_INITIATE, Status::PAYMENT_PENDING])) {
            return;
        }
        $deposit->status = Status::PAYMENT_REJECT;
        $deposit->save();

        $adminNotification            = new AdminNotification();
        $adminNotification->user_id   = $deposit->user_id;
        $adminNotification->title     = 'Deposit ' . $status . ' via OST (trx: ' . $deposit->trx . ')';
        $adminNotification->click_url = urlPath('admin.deposit.details', $deposit->id);
        $adminNotification->save();
    }

    private function handleWithdrawEvent($withdraw, $event, $data) {
        if (str_ends_with($event, '.success')) {
            $this->markWithdrawSuccess($withdraw, @$data->pg_reference);
            return;
        }
        if (str_ends_with($event, '.failed') || str_ends_with($event, '.rejected')) {
            $message = @$data->pg_error_msg ?: 'Payout was not completed by the payment processor';
            $this->refundWithdraw($withdraw, $message);
        }
    }

    private function markWithdrawSuccess($withdraw, $pgReference) {
        if ($withdraw->status != Status::PAYMENT_PENDING) {
            return;
        }
        $withdraw->status = Status::PAYMENT_SUCCESS;
        $withdraw->save();

        notify($withdraw->user, 'WITHDRAW_APPROVE', [
            'method_name'     => $withdraw->method->name,
            'method_currency' => $withdraw->currency,
            'method_amount'   => showAmount($withdraw->final_amount, currencyFormat: false),
            'amount'          => showAmount($withdraw->amount, currencyFormat: false),
            'charge'          => showAmount($withdraw->charge, currencyFormat: false),
            'rate'            => showAmount($withdraw->rate, currencyFormat: false),
            'trx'             => $withdraw->trx,
            'post_balance'    => showAmount($withdraw->user->balance, currencyFormat: false),
            'admin_details'   => $pgReference ? 'Payout completed. PG reference: ' . $pgReference : 'Payout completed',
        ]);
    }

    private function refundWithdraw($withdraw, $message) {
        if ($withdraw->status != Status::PAYMENT_PENDING) {
            return;
        }
        $withdraw->status         = Status::PAYMENT_REJECT;
        $withdraw->admin_feedback = $message;
        $withdraw->save();

        $user = $withdraw->user;
        $user->balance += $withdraw->amount;
        $user->save();

        $transaction               = new Transaction();
        $transaction->user_id      = $withdraw->user_id;
        $transaction->amount       = $withdraw->amount;
        $transaction->post_balance = $user->balance;
        $transaction->charge       = 0;
        $transaction->trx_type     = '+';
        $transaction->remark       = 'withdraw_reject';
        $transaction->details      = 'Refunded for withdrawal rejection';
        $transaction->trx          = $withdraw->trx;
        $transaction->save();

        notify($user, 'WITHDRAW_REJECT', [
            'method_name'     => $withdraw->method->name,
            'method_currency' => $withdraw->currency,
            'method_amount'   => showAmount($withdraw->final_amount, currencyFormat: false),
            'amount'          => showAmount($withdraw->amount, currencyFormat: false),
            'charge'          => showAmount($withdraw->charge, currencyFormat: false),
            'rate'            => showAmount($withdraw->rate, currencyFormat: false),
            'trx'             => $withdraw->trx,
            'post_balance'    => showAmount($user->balance, currencyFormat: false),
            'admin_details'   => $message,
        ]);
    }

    /*
     * Extract the bank destination from the withdraw method form data
     */
    private static function destinationInfo($withdrawInformation) {
        $info = ['account_number' => null, 'bank_code' => null, 'account_holder_name' => null];

        $patterns = [
            'account_number'      => ['account number', 'account no', 'account_number', 'destination_account', 'rekening', 'norek'],
            'bank_code'           => ['bank code', 'bank_code', 'kode bank', 'bank'],
            'account_holder_name' => ['account holder name', 'account name', 'account_holder_name', 'holder name', 'nama pemilik', 'nama rekening', 'atas nama', 'pemilik rekening'],
        ];

        foreach ((array) $withdrawInformation as $item) {
            $name  = is_array($item) ? @$item['name'] : @$item->name;
            $value = is_array($item) ? @$item['value'] : @$item->value;
            $label = strtolower(trim((string) $name));
            foreach ($patterns as $key => $keywords) {
                if ($info[$key] !== null) {
                    continue;
                }
                foreach ($keywords as $keyword) {
                    if ($label == $keyword || str_contains($label, $keyword)) {
                        $info[$key] = trim((string) $value);
                        break;
                    }
                }
            }
        }

        if (!$info['account_number'] || !$info['bank_code'] || !$info['account_holder_name']) {
            return null;
        }
        return $info;
    }

    /*
     * Charge creation path — configurable via the gateway parameter
     * `charge_path` (e.g. `/gateway/mgm/payin` for the MGM wallet).
     * Default: OST direct charge `/v5/gateway/charge/create`, or the
     * legacy cashier endpoint when direct mode is off.
     */
    private static function chargePath($param = null) {
        $param = $param ?: self::credentials();
        $path  = trim(@$param->charge_path ?: '');
        if ($path) {
            return $path;
        }
        return self::directMode($param) ? '/v5/gateway/charge/create' : '/merchant/payment/create';
    }

    /*
     * Direct gateway mode (OST) enabled? Gateway parameter `direct_mode` = yes/1/true/on.
     * Requires the API key to be whitelisted in DIRECT_PG_API_KEYS on the platform.
     */
    private static function directMode($param = null) {
        $param = $param ?: self::credentials();
        return filter_var(@$param->direct_mode, FILTER_VALIDATE_BOOLEAN);
    }

    /*
     * Friendly label for the platform's raw method value
     */
    private static function methodLabel($method) {
        $labels = ['QRCODE' => 'QRIS', 'IDNVA' => 'Virtual Account', 'WALLET' => 'E-Wallet', 'QRIS' => 'QRIS'];
        return @$labels[strtoupper((string) $method)] ?: $method;
    }

    /*
     * Gateways served by this controller family (RoguePay + MGM wallet)
     */
    private static function isOstFamily($alias) {
        return in_array($alias, [self::ALIAS, 'MgmQris', 'MgmVa', 'MgmWallet']);
    }

    /*
     * Deposits that can only be tracked locally — MGM wallet charges
     * (charge_path /gateway/mgm/*) have no public status endpoint; the
     * platform webhook updates the deposit row instead.
     */
    private static function localStatusOnly($param = null) {
        if (!$param) {
            return false;
        }
        return str_contains(trim((string) @$param->charge_path), '/gateway/mgm/');
    }

    /*
     * Candidate status lookup paths, newest platform first.
     * OST:   GET /v5/gateway/charge/{ref_id}
     * Legacy: GET /v5/merchant/payment/{ref_id} and /merchant/payment/{ref_id}
     */
    private static function statusCandidates($param = null) {
        if (self::directMode($param)) {
            return ['/v5/gateway/charge/', '/v5/merchant/payment/', '/merchant/payment/'];
        }
        return ['/merchant/payment/', '/v5/gateway/charge/', '/v5/merchant/payment/'];
    }

    /*
     * Fetch the current status of a payment, trying every candidate path.
     * Returns the first successful platform response.
     */
    private static function getStatus($refId, $param = null) {
        foreach (self::statusCandidates($param) as $path) {
            $response = self::apiRequest('GET', $path . $refId, null, $param);
            if (@$response->success && @$response->data) {
                return $response;
            }
        }
        return (object) ['success' => false, 'message' => 'Unable to check payment status'];
    }

    /*
     * Base URL used for payout (disbursement) requests.
     *
     * Defaults to the legacy roguecdn platform where JAYAPAY payouts run;
     * override via the gateway parameter `payout_base_url` once the OST
     * platform (api.nextcdn.online) supports disbursement as well.
     */
    private static function payoutBase($param = null) {
        $param = $param ?: self::credentials();
        $base  = trim(@$param->payout_base_url ?: '');
        return $base ?: 'https://api.roguecdn.online';
    }

    /*
     * Payout credentials — the disbursement platform may use a different
     * merchant than the pay-in platform. Falls back to the main API
     * credentials when `payout_api_key` / `payout_secret_key` are empty.
     */
    private static function payoutParam($param = null) {
        $param = $param ?: self::credentials();
        $copy  = clone $param;
        if (trim(@$param->payout_api_key ?: '')) {
            $copy->api_key = $param->payout_api_key;
        }
        if (trim(@$param->payout_secret_key ?: '')) {
            $copy->secret_key = $param->payout_secret_key;
        }
        return $copy;
    }

    /*
     * Order lifetime in minutes (OST platform default is 30)
     */
    private static function orderTtl($param = null) {
        $param = $param ?: self::credentials();
        $ttl   = (int) @$param->expiry_period;
        return $ttl > 0 ? $ttl : 30;
    }

    /*
     * API credentials of the gateway (from the currency config, fallback to template)
     */
    private static function credentials() {
        $param = new \stdClass();

        $gateway = Gateway::where('alias', self::ALIAS)->first();
        if (!$gateway) {
            return $param;
        }

        $currency = $gateway->currencies()->first();
        if ($currency && $currency->gateway_parameter) {
            foreach (json_decode($currency->gateway_parameter) as $key => $value) {
                $param->$key = $value;
            }
            return $param;
        }

        $template = json_decode($gateway->gateway_parameters);
        foreach ($template ?: [] as $key => $item) {
            $param->$key = @$item->value ?: '';
        }
        return $param;
    }
}
