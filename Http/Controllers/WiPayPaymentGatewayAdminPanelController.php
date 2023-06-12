<?php

namespace Modules\WiPayPaymentGateway\Http\Controllers;

use App\Helpers\ModuleMetaData;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WiPayPaymentGatewayAdminPanelController extends Controller
{
    public function settings()
    {
        $all_module_meta_data = (new ModuleMetaData("WiPayPaymentGateway"))->getExternalPaymentGateway();
        $wipay = array_filter($all_module_meta_data,function ( $item ){
            if ($item->name === "WiPay"){
                return $item;
            }
        });
        $wipay = current($wipay);
        return  view("wipaypaymentgateway::admin.settings",compact("wipay"));
    }

    public function settingsUpdate(Request $request){
        $request->validate([
            "wipay_account_number" => "required|string",
            "wipay_account_api_key" => "required|string",
            "wipay_currency" => "required|string",
            "wipay_fee_structure" => "required|string",
            "wipay_country_code" => "required|string",
        ]);

        update_static_option("wipay_account_number",$request->wipay_account_number);
        update_static_option("wipay_account_api_key",$request->wipay_account_api_key);
        update_static_option("wipay_currency",$request->wipay_currency);
        update_static_option("wipay_fee_structure",$request->wipay_fee_structure);
        update_static_option("wipay_country_code",$request->wipay_country_code);

        if(is_null(tenant())){
            $jsonModifier = json_decode(file_get_contents("core/Modules/WiPayPaymentGateway/module.json"));
            $jsonModifier->nazmartMetaData->paymentGateway->status = $request?->wipay_status === 'on';
            $jsonModifier->nazmartMetaData->paymentGateway->test_mode = $request?->wipay_test_mode_status === 'on';
            $jsonModifier->nazmartMetaData->paymentGateway->admin_settings->show_admin_landlord = $request?->wipay_landlord_status === 'on';
            $jsonModifier->nazmartMetaData->paymentGateway->admin_settings->show_admin_tenant = $request?->wipay_tenant_status === 'on';

            file_put_contents("core/Modules/WiPayPaymentGateway/module.json",json_encode($jsonModifier));
        }



        return back()->with(["msg" => __("Settings Update"),"type" => "success"]);
    }
}
