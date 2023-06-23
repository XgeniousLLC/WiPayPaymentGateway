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
                        <option @if(get_static_option('wipay_account_number') === "JMD") selected @endif value="JMD">JMD</option>
                        <option @if(get_static_option('wipay_account_number') === "TTD") selected @endif value="TTD">TTD</option>
                        <option @if(get_static_option('wipay_account_number') === "USD") selected @endif value="USD">USD</option>
                    </x-fields.select>
                    <x-fields.select name="wipay_fee_structure" title="{{__('Fee Structure')}}">
                        <option @if(get_static_option('wipay_fee_structure') === "customer_pay") selected @endif value="customer_pay">{{__('Customer Will Pay')}}</option>
                        <option @if(get_static_option('wipay_fee_structure') === "merchant_absorb") selected @endif value="merchant_absorb">{{__("Merchant Pay")}}</option>
                        <option @if(get_static_option('wipay_fee_structure') === "split") selected @endif value="split">{{__("Slip To Both")}}</option>
                    </x-fields.select>
                    <x-fields.select name="wipay_country_code" title="{{__('Country')}}">
                        <option @if(get_static_option('wipay_country_code') === "TT") selected @endif value="TT">TT</option>
                        <option  @if(get_static_option('wipay_country_code') === "JM") selected @endif value="JM">JM</option>
                        <option  @if(get_static_option('wipay_country_code') === "BB") selected @endif value="BB">BB</option>
                    </x-fields.select>

                    <x-fields.switcher label="{{__('WiPay Test Mode Enable/Disable')}}" name="wipay_test_mode_status" value="{{get_static_option('wipay_test_mode')}}"/>
                    

                    @if(is_null(tenant()))
                    <x-fields.switcher label="{{__('WiPay Enable/Disable Landlord Websites')}}" name="wipay_landlord_status" value="{{$wipay->admin_settings->show_admin_landlord}}"/>
                    <x-fields.switcher label="{{__('WiPay Enable/Disable Tenant Websites')}}" name="wipay_tenant_status" value="{{$wipay->admin_settings->show_admin_tenant}}"/>
                    @else 
                        <x-fields.switcher label="{{__('WiPay Enable/Disable')}}" name="wipay_status" value="{{get_static_option('wipay_status')}}"/>
                    @endif
                    <button type="submit" class="btn btn-gradient-primary mt-5 me-2">{{__('Save Changes')}}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
