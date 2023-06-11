@extends(route_prefix()."admin.admin-master")
@section('title') {{__('WiPay  Settings')}}@endsection
@section("content")
    <div class="col-12 stretch-card">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title mb-4">{{__('WiPay Settings')}}</h4>
                <x-error-msg/>
                <x-flash-msg/>
                <form class="forms-sample" method="post" action="{{route('wipaypoaymentgateway.'.route_prefix().'admin.settings')}}">
                    @csrf
                    <x-fields.input type="text" value="{{get_static_option('wipay_account_number')}}" name="wipay_account_number" label="{{__('WiPay Account Number')}}"/>
                    <x-fields.input type="text" value="{{get_static_option('wipay_account_api_key')}}" name="wipay_account_api_key" label="{{__('WiPay Account Api Key')}}"/>

                    <x-fields.select name="wipay_currency" title="{{__('Currency')}}">
                        <option value="JMD">JMD</option>
                        <option value="TTD">TTD</option>
                        <option selected value="USD">USD</option>
                    </x-fields.select>
                    <x-fields.select name="wipay_fee_structure" title="{{__('Fee Structure')}}">
                        <option selected value="customer_pay">{{__('Customer Will Pay')}}</option>
                        <option value="merchant_absorb">{{__("Merchant Pay")}}</option>
                        <option value="split">{{__("Slip To Both")}}</option>
                    </x-fields.select>
                    <x-fields.select name="wipay_country_code" title="{{__('Country')}}">
                        <option selected value="TT">TT</option>
                        <option value="JM">JM</option>
                        <option value="BB">BB</option>
                    </x-fields.select>

                    <x-fields.switcher label="{{__('WiPay Test Mode Enable/Disable')}}" name="wipay_test_mode_status" value="{{$wipay->test_mode}}"/>

                    <x-fields.switcher label="{{__('WiPay Enable/Disable')}}" name="wipay_status" value="{{$wipay->status}}"/>
                    @if(is_null(tenant()))
                    <x-fields.switcher label="{{__('WiPay Enable/Disable Landlord Websites')}}" name="wipay_landlord_status" value="{{$wipay->admin_settings->show_admin_landlord}}"/>
                    <x-fields.switcher label="{{__('WiPay Enable/Disable Tenant Websites')}}" name="wipay_tenant_status" value="{{$wipay->admin_settings->show_admin_tenant}}"/>
                    @endif
                    <button type="submit" class="btn btn-gradient-primary mt-5 me-2">{{__('Save Changes')}}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
