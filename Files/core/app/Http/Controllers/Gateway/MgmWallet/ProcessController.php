<?php

namespace App\Http\Controllers\Gateway\MgmWallet;

use App\Http\Controllers\Gateway\RoguePay\ProcessController as RoguePayProcessController;

/*
 * MGM E-Wallet deposit (MGM wallet) — same OST platform, dedicated wallet.
 * Charges via POST /gateway/mgm/payin (method WALLET — DANA/OVO/GoPay/
 * ShopeePay etc.); the customer is redirected to the MGM cashier (pay_url).
 */
class ProcessController extends RoguePayProcessController {
    const ALIAS = 'MgmWallet';
}
