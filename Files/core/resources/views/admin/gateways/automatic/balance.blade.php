@extends('admin.layouts.app')
@section('panel')
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="mb-4">{{ __($gateway->name) }} - @lang('Balance')</h4>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="card border border--primary">
                                <div class="card-body text-center">
                                    <h5 class="text-muted mb-2">@lang('Available')</h5>
                                    <h2 class="text-success">{{ number_format((float) @$balance->available, 0, ',', '.') }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border border--warning">
                                <div class="card-body text-center">
                                    <h5 class="text-muted mb-2">@lang('Held')</h5>
                                    <h2 class="text-warning">{{ number_format((float) @$balance->held, 0, ',', '.') }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border border--primary">
                                <div class="card-body text-center">
                                    <h5 class="text-muted mb-2">@lang('Total')</h5>
                                    <h2 class="text-primary">{{ number_format((float) @$balance->total, 0, ',', '.') }}</h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 text-center">
                        <a href="{{ route('admin.gateway.automatic.balance', $gateway->alias) }}" class="btn btn--primary">
                            <i class="las la-sync"></i> @lang('Refresh')
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('breadcrumb-plugins')
    <x-back route="{{ route('admin.gateway.automatic.index') }}" />
@endpush
