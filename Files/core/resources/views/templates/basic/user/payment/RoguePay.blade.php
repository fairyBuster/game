@extends('Template::layouts.master')

@section('content')
    <div class="pt-120 pb-120">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card card-deposit text-center">
                        <div class="card-header card-header-bg">
                            <h3>@lang('Payment Preview')</h3>
                        </div>
                        <div class="card-body card-body-deposit text-center">
                            <h4 class="my-2">
                                @lang('Pay')
                                <span class="text-success">{{ showAmount($deposit->final_amount, currencyFormat: false) }} {{ __($deposit->method_currency) }}</span>
                                @lang('via') <span class="text-success">{{ __(@$data->method_label ?: @$data->method) }}</span>
                            </h4>

                            @if (@$data->pay_data_type == 'QR_CODE' && @$data->pay_data)
                                <img class="img-fluid mx-auto d-block mb-3"
                                     src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&data={{ urlencode($data->pay_data) }}"
                                     alt="@lang('QR Code')" style="max-width: 260px;">
                                <p class="text-muted mb-3">@lang('Scan the QR code with your payment app to complete the payment.')</p>
                            @elseif (in_array(@$data->pay_data_type, ['QR_URL', 'QR_IMAGE']) && @$data->pay_data)
                                <img class="img-fluid mx-auto d-block mb-3" src="{{ $data->pay_data }}" alt="@lang('QR Code')" style="max-width: 260px;">
                                <p class="text-muted mb-3">@lang('Scan the QR code with your payment app to complete the payment.')</p>
                            @elseif (@$data->pay_data_type == 'CASHIER_URL' && @$data->pay_data)
                                <a href="{{ $data->pay_data }}" target="_blank" class="btn btn--primary my-3">@lang('Pay Now')</a>
                            @elseif (@$data->pay_data)
                                <div class="input-group mb-3 mx-auto" style="max-width: 420px;">
                                    <input type="text" class="form-control" id="roguePayCode" value="{{ $data->pay_data }}" readonly>
                                    <button type="button" class="btn btn--primary" onclick="roguePayCopy()">@lang('Copy')</button>
                                </div>
                                <p class="text-muted mb-3">@lang('Use the code above to complete the payment.')</p>
                            @endif

                            <div class="d-flex justify-content-center flex-wrap gap-2 mb-3">
                                <div class="badge bg-warning p-2">
                                    @lang('Ref'):
                                    <span class="text-dark fw-bold">{{ @$data->ref_id }}</span>
                                </div>
                                @if (@$data->expires_at)
                                    <div class="badge bg-danger p-2">
                                        @lang('Expires in'):
                                        <span class="text-white fw-bold" id="roguePayCountdown">{{ $data->expires_at }}</span>
                                    </div>
                                @endif
                            </div>

                            <p class="text-muted mb-0">
                                <i class="las la-sync la-spin"></i>
                                @lang('Waiting for payment confirmation...')
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function($) {
            const trx = "{{ $deposit->trx }}";
            const expiresAt = "{{ @$data->expires_at }}";

            // Countdown to expiry
            if (expiresAt) {
                const target = new Date(expiresAt).getTime();
                const el = document.getElementById('roguePayCountdown');
                const tick = () => {
                    const diff = target - Date.now();
                    if (diff <= 0) {
                        el.textContent = '00:00';
                        clearInterval(countdownTimer);
                        clearInterval(pollTimer);
                        return;
                    }
                    const m = Math.floor(diff / 60000);
                    const s = Math.floor((diff % 60000) / 1000);
                    el.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                };
                const countdownTimer = setInterval(tick, 1000);
                tick();
            }

            // Poll payment status
            const pollTimer = setInterval(() => {
                $.post("{{ route('user.deposit.roguepay.check') }}", { trx: trx, _token: "{{ csrf_token() }}" })
                    .done(function(res) {
                        if (!res.success) return;
                        if (['paid', 'failed', 'expired', 'cancelled'].includes(res.status)) {
                            clearInterval(pollTimer);
                            window.location.href = "{{ route('user.deposit.history') }}";
                        }
                    });
            }, 10000);
        })(jQuery);

        function roguePayCopy() {
            const input = document.getElementById('roguePayCode');
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
        }
    </script>
@endpush
