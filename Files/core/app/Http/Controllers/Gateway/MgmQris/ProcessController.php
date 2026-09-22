<?php

namespace App\Http\Controllers\Gateway\MgmQris;

use App\Http\Controllers\Gateway\RoguePay\ProcessController as RoguePayProcessController;

/*
 * MGM QRIS deposit (MGM wallet) — same OST platform, dedicated wallet.
 *
 * Charges are created via POST /gateway/mgm/payin (method QRCODE) which is
 * configured through the gateway parameter `charge_path` / `default_method`
 * on this gateway — no code duplication, the RoguePay (OST) controller
 * holds all shared logic (signing, redirect, webhook matching).
 */
class ProcessController extends RoguePayProcessController {
    const ALIAS = 'MgmQris';
}
