<?php

namespace App\Http\Controllers\Gateway\MgmVa;

use App\Http\Controllers\Gateway\RoguePay\ProcessController as RoguePayProcessController;

/*
 * MGM VA / Internet Banking deposit (MGM wallet) — same OST platform,
 * dedicated wallet. Charges via POST /gateway/mgm/payin (method IDNVA);
 * the customer is redirected to the MGM cashier (pay_url).
 */
class ProcessController extends RoguePayProcessController {
    const ALIAS = 'MgmVa';
}
