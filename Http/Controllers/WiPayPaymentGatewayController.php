<?php

namespace Modules\WiPayPaymentGateway\Http\Controllers;

use App\Enums\PaymentRouteEnum;
use App\Events\TenantRegisterEvent;
use App\Helpers\FlashMsg;
use App\Helpers\Payment\DatabaseUpdateAndMailSend\LandlordPricePlanAndTenantCreate;
use App\Mail\BasicMail;
use App\Mail\PlaceOrder;
use App\Mail\ProductOrderEmail;
use App\Mail\ProductOrderEmailAdmin;
use App\Mail\ProductOrderManualEmail;
use App\Mail\TenantCredentialMail;
use App\Models\PaymentLogs;
use App\Models\ProductOrder;
use App\Models\Tenant;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Wallet\Entities\Wallet;
use Modules\Wallet\Entities\WalletHistory;
use Modules\Wallet\Http\Services\WalletService;
use Xgenious\Paymentgateway\Base\PaymentGatewayHelpers;
use Xgenious\Paymentgateway\Facades\XgPaymentGateway;

class WiPayPaymentGatewayController extends Controller
{
    /**
     * Display a listing of the resource.
     * @method chargeCustomer
     *
     * @return checkout url redirect user to the payment gateway website
     *
     * this method will receive all the information from the main script, while any user select any payment gateway for payment. this method will receive all of that data and make it ready for redirect user to the payment provider website for payment.
     *
     */
    public function chargeCustomer($args)
    {
        //detect it is coming from which method for which kind of payment
        //detect it for landlord or tenant website
        if (in_array($args["payment_type"],["price_plan","deposit"]) && $args["payment_for"] === "landlord"){
            return $this->chargeCustomerForLandlordPricePlanPurchase($args);
        }
        // all tenant payment process will from here....
        if (in_array($args["payment_type"],["shop_checkout"]) && $args["payment_for"] === "tenant"){
            return $this->chargeCustomerForLandlordPricePlanPurchase($args);
        }
        abort(404);
        //make a request to Siteways server to generate checkout url based on static data
    }

    /**
     * @method chargeCustomerForLandlordPricePlanPurchase
     * @param array $arg
     *
     * This method is responsible for sending request to the payment gatewy provider for redirect or charge your customer
     * */
    private function chargeCustomerForLandlordPricePlanPurchase($args){
        $paymentInfo = $args["payment_details"];
        //check payment type wallet or not
        $email = $paymentInfo['email'] ?? " ";
        $name = $paymentInfo['name'] ?? " ";
        $product_name = $paymentInfo['package_name'] ?? " ";
        $success_url = $args["success_url"];
        $ipn_route_prefix = "landlord";

        $reference = [
            'order_id' => XgPaymentGateway::wrapped_id($paymentInfo['id']),
            'payment_type' => $args['payment_type']
        ];



        if ($args["payment_type"] === "deposit" && $args["payment_for"] === "landlord"){
            $user_details = User::find($args["payment_details"]["user_id"] ?? "");
            if (!is_null($user_details)){
                $email = $user_details->email;
                $name = $user_details->name;
            }
            $product_name = __("Wallet Recharge");
            $success_url = route("landlord.user.wallet.history");
            $reference["history_id"] = $paymentInfo['id'] ?? " ";
        }



        if($args["payment_type"] === "shop_checkout" && $args["payment_for"] === "tenant"){
            $product_name = __("Payment for order")." ".$paymentInfo['id'];
            $ipn_route_prefix = "tenant";
        }

        //https://tt.wipayfinancial.com/plugins/payments/request
        //https://jm.wipayfinancial.com/plugins/payments/request
        //https://bb.wipayfinancial.com/plugins/payments/request

        $res = Http::acceptJson()->asForm()->post("https://".strtolower(get_static_option('wipay_country_code','TT')).".wipayfinancial.com/plugins/payments/request",[
            "account_number" => get_static_option('wipay_account_number',"1234567890") , //If environment is sandbox, then you must use the WiPay SANDBOX Account Number 1234567890.
            "currency" => get_static_option("wipay_currency","USD"), // JMD, TTD, USD
            "environment" => "sandbox" , //live, sandbox,
            "fee_structure" => get_static_option('wipay_fee_structure','merchant_absorb'), //customer_pay, merchant_absorb, split, who will pay wipay transaction fee,
            "method" => "credit_card",
            "order_id" => PaymentGatewayHelpers::wrapped_id($paymentInfo['id']), //order_id by application
            "origin" => get_static_option('site_title',"payment"),  //Your application's custom unique identifier for this transaction.
            "total" => number_format($args['total'],2), //decimal value,
            "email" => $email,
            "name" => $name,
            'avs' => '0',
            "data" => json_encode($reference),
            'country_code' => get_static_option('wipay_country_code','TT'),
            'response_url' => route("wipaypoaymentgateway.".$ipn_route_prefix.".price.plan.ipn"), //get: callback for get data about the payment
        ]);

        $redirect_url = $res->object()?->url;
        if (is_null($redirect_url)){
            abort(501,$res->object()?->message);
        }
        return redirect()->away($redirect_url);
    }


    /**
     * @method landlordPricePlanIpn
     * param $request
     *
     *  this is ipn/callback/webhook method for the payment gateway i am implementing, it will received information form the payment gatewya after successful payment by the user
     *
     * */
    public function landlordPricePlanIpn(Request $request){

        //write code for success callback , to receive callback and check it again that it is actually paid or not.
        $payment_data = $this->capturePaymentAndVerifyAgain();
        if ($payment_data["status"] === "complete"){
            if ($payment_data["order_type"] === "price_plan"){
                $this->runPostPaymentProcessForLandlordPricePlanSuccessPayment($payment_data);
                //redirect to success page
                return redirect()->to(route('landlord.frontend.order.payment.success', random_int(111111,999999).$payment_data['order_id'].random_int(111111,999999)));
            }elseif ($payment_data["order_type"] === "deposit"){
               return $this->runPostPaymentProcessForLandlordWalletDepositSuccessPayment($payment_data);
                //todo run order type deposit post payment processes
            }elseif ($payment_data["order_type"] === "shop_checkout"){
                $this->runPostPaymentProcessForTenantdShopCheckoutSuccessPayment($payment_data);
                //todo run order type deposit post payment processes
            }
        }
        //write code if the payment verify failed
    }

    /**
     * @method TenantSiteswayIpn
     * param $request
     *
     *  this is ipn/callback/webhook method for the payment gateway i am implementing, it will received information form the payment gatewya after successful payment by the user
     *
     * */
    public function TenantSiteswayIpn(Request $request){

        //write code for success callback , to receive callback and check it again that it is actually paid or not.
        $payment_data = $this->capturePaymentAndVerifyAgain();

        if ($payment_data["status"] === "complete"){
            if ($payment_data["order_type"] === "shop_checkout"){
                $this->runPostPaymentProcessForTenantdShopCheckoutSuccessPayment($payment_data);
                //todo run order type deposit post payment processes
                $order_id = random_int(111111,999999) . $payment_data['order_id'] . random_int(111111,999999);
                return redirect()->route('tenant.user.frontend.order.payment.success',$order_id);
            }
        }
        //write code if the payment verify failed
    }

    /**
     * @methodn capturePaymentAndVerifyAgain
     * return array $payment_data
     *
     * this method is responsible for capture payment info from success callback and verify it before return payment information to the post payment processor
     * */
    private function capturePaymentAndVerifyAgain(){

//        dd(\request()->all());

        //todo check status is success
        $status = \request()->status;
        $hash = \request()->hash;
        $order_id = \request()->order_id;
        $transaction_id = \request()->transaction_id;
        $data = json_decode(\request()->data);
        $data = json_decode($data);

        if (!empty($hash) && !empty($transaction_id) && !empty($order_id) && $status === 'success'){

            // add condition
            $charge_amount = 0;
            if ($data->payment_type === 'price_plan' ){
                $paymentLogs = PaymentLogs::find(PaymentGatewayHelpers::unwrapped_id($order_id));
                if (is_null($paymentLogs)){
                    abort(501,__("order id not found in our database"));
                }
                $charge_amount = $paymentLogs->package_price;
            }

            //todo check if it is wallet or not
            if ($data->payment_type === 'deposit' && !empty($data->history_id)){
                $walletHistory = WalletHistory::find($data->history_id);
                if (is_null($paymentLogs)){
                    abort(501,__("wallet history id not found in our database"));
                }
                $charge_amount = $walletHistory->amount;
            }
            if ($data->payment_type === 'shop_checkout'){
            //todo fetch tenant shop total amount...
                $shopOrder = ProductOrder::find(PaymentGatewayHelpers::unwrapped_id($order_id ?? ""));
                if (is_null($shopOrder)){
                    abort(501,__("shop order id not found in our database"));
                }
                $charge_amount = $shopOrder->total_amount;
            }

            $generate_hash = md5($transaction_id.number_format($charge_amount,2).get_static_option('wipay_account_api_key','123'));
//        $generate_hash = md5($transaction_id.number_format(350,2).get_static_option('wipay_account_number'));
            //todo get database price total
//        dd($transaction_id,get_static_option('wipay_account_number'));

//            dd($charge_amount,$paymentLogs,hash_equals($hash,$generate_hash),$hash,$generate_hash,$transaction_id.number_format($charge_amount,2).get_static_option('wipay_account_api_key','123'));
            if (hash_equals($hash,$generate_hash)){
                return $this->verified_data([
                    'status' => 'complete',
                    'transaction_id' => $transaction_id ,
                    'order_id' => PaymentGatewayHelpers::unwrapped_id($order_id ?? ""),
                    'order_type' => $data->payment_type ?? "",
                    "history_id" => property_exists($data,"history_id") ? $data->history_id : " "
                ]);
            }
            //dd(hash_equals($hash,$generate_hash),$generate_hash,$hash,\request()->all(),PaymentGatewayHelpers::unwrapped_id($order_id),$data);

        }

        return $this->verified_data([
            'status' => 'failed',
            'order_id' => PaymentGatewayHelpers::unwrapped_id($order_id ?? ""),
            'order_type' => $data->payment_type ?? ""
        ]);
    }

    /**
     * @method runPostPaymentProcessForLandlordPricePlanSuccessPayment
     * @param array $payment_data
     * this method will run process for after a successfully payment for landlord price plan payment.
     * */
    private function runPostPaymentProcessForLandlordPricePlanSuccessPayment($payment_data)
    {
        if (isset($payment_data['status']) && $payment_data['status'] === 'complete') {
            try {
                $this->landlordPricePlanPostPaymentUpdateDatabase($payment_data['order_id'], $payment_data['transaction_id']);
                $this->landlordPricePlanPostPaymentSendOrderMail($payment_data['order_id']);
                $this->landlordPricePlanPostPaymentTenantCreateEventWithCredentialMail($payment_data['order_id']);
                $this->landlordPricePlanPostPaymentUpdateTenant($payment_data);

            } catch (\Exception $exception) {
                $message = $exception->getMessage();
                if(str_contains($message,'Access denied')){
                    if(request()->ajax()){
                        abort(462,__('Database created failed, Make sure your database user has permission to create database'));
                    }
                }

                $payment_details = PaymentLogs::where('id',$payment_data['order_id'])->first();
                if(empty($payment_details))
                {
                    abort(500,__('Does not exist, Tenant does not exists'));
                }
                LandlordPricePlanAndTenantCreate::store_exception($payment_details->tenant_id,'Domain create',$exception->getMessage(), 0);

                //todo: send an email to admin that this user databse could not able to create automatically

                try {
                    $message = sprintf(__('Database Creating failed for user id %1$s , please checkout admin panel and generate database for this user from admin panel manually'),
                        $payment_details->user_id);
                    $subject = sprintf(__('Database Crating failed for user id %1$s'),$payment_details->user_id);
                    Mail::to(get_static_option('site_global_email'))->send(new BasicMail($message,$subject));

                } catch (\Exception $e) {
                    LandlordPricePlanAndTenantCreate::store_exception($payment_details->tenant_id,'domain failed email',$e->getMessage(), 0);
                }
            }

            $order_id = wrap_random_number($payment_data['order_id']);
            return redirect()->route("landlord.frontend.order.payment.success", $order_id);
        }

        return $this->landlordPricePlanPostPaymentCancelPage();
    }

    /**
     * @method landlordPricePlanPostPaymentUpdateDatabase
     * @param id $order_id, string  $transaction_id
     *
     * update database for the payment success record
     * */

    private function landlordPricePlanPostPaymentUpdateDatabase($order_id, $transaction_id)
    {
        PaymentLogs::where('id', $order_id)->update([
            'transaction_id' => $transaction_id,
            'status' => 'complete',
            'payment_status' => 'complete',
            'updated_at' => Carbon::now()
        ]);
    }

    /**
     * @method landlordPricePlanPostPaymentSendOrderMail
     * @param id $order_id
     * send mail to admin and user regarding the payment
     * */
    private function landlordPricePlanPostPaymentSendOrderMail($order_id)
    {
        $package_details = PaymentLogs::where('id', $order_id)->first();
        $all_fields = [];
        unset($all_fields['package']);
        $all_attachment = [];
        $order_mail = get_static_option('order_page_form_mail') ? get_static_option('order_page_form_mail') : get_static_option('site_global_email');

        try {
            Mail::to($order_mail)->send(new PlaceOrder($all_fields, $all_attachment, $package_details, "admin", 'regular'));
            Mail::to($package_details->email)->send(new PlaceOrder($all_fields, $all_attachment, $package_details, 'user', 'regular'));

        } catch (\Exception $e) {
            //return redirect()->back()->with(['type' => 'danger', 'msg' => $e->getMessage()]);
        }
    }

    /**
     * @method landlordPricePlanPostPaymentTenantCreateEventWithCredentialMail
     * @param int $order_id
     * create tenant, create database, migrate database table, seed database dummy data, with a default admin account
     * */
    private function landlordPricePlanPostPaymentTenantCreateEventWithCredentialMail($order_id)
    {
        $log = PaymentLogs::findOrFail($order_id);
        if (empty($log))
        {
            abort(462,__('Does not exist, Tenant does not exists'));
        }

        $user = User::where('id', $log->user_id)->first();
        $tenant = Tenant::find($log->tenant_id);

        if (!empty($log) && $log->payment_status == 'complete' && is_null($tenant)) {
            event(new TenantRegisterEvent($user, $log->tenant_id, get_static_option('default_theme')));
            try {
                $raw_pass = get_static_option_central('tenant_admin_default_password') ??'12345678';
                $credential_password = $raw_pass;
                $credential_email = $user->email;
                $credential_username = get_static_option_central('tenant_admin_default_username') ?? 'super_admin';

                Mail::to($credential_email)->send(new TenantCredentialMail($credential_username, $credential_password));

            } catch (\Exception $e) {

            }

        } else if (!empty($log) && $log->payment_status == 'complete' && !is_null($tenant) && $log->is_renew == 0) {
            try {
                $raw_pass = get_static_option_central('tenant_admin_default_password') ?? '12345678';
                $credential_password = $raw_pass;
                $credential_email = $user->email;
                $credential_username = get_static_option_central('tenant_admin_default_username') ?? 'super_admin';

                Mail::to($credential_email)->send(new TenantCredentialMail($credential_username, $credential_password));

            } catch (\Exception $exception) {
                $message = $exception->getMessage();
                if(str_contains($message,'Access denied')){
                    abort(463,__('Database created failed, Make sure your database user has permission to create database'));
                }
            }
        }

        return true;
    }
/**
 * @method landlordPricePlanPostPaymentUpdateTenant
 * @param array $payment_data
 *
 * */
    private function landlordPricePlanPostPaymentUpdateTenant(array $payment_data)
    {
        try{
            $payment_log = PaymentLogs::where('id', $payment_data['order_id'])->first();
            $tenant = Tenant::find($payment_log->tenant_id);

            \DB::table('tenants')->where('id', $tenant->id)->update([
                'renew_status' => $renew_status = is_null($tenant->renew_status) ? 0 : $tenant->renew_status+1,
                'is_renew' => $renew_status == 0 ? 0 : 1,
                'start_date' => $payment_log->start_date,
                'expire_date' => get_plan_left_days($payment_log->package_id, $tenant->expire_date)
            ]);


        } catch (\Exception $exception) {
            $message = $exception->getMessage();
            if(str_contains($message,'Access denied')){
                abort(462,__('Database created failed, Make sure your database user has permission to create database'));
            }
        }
    }

    /**
     * @method landlordPricePlanPostPaymentCancelPage
     * @return static cancel page for landlord price plan order
     * */

    private function landlordPricePlanPostPaymentCancelPage()
    {
        return redirect()->route('landlord.frontend.order.payment.cancel.static');
    }

    /**
     * payment gateway verified data return as payment_data
     * @method verified_data
     * @param $args
     * @return array $payment_data
     * */
    private function verified_data(array $args)
    {
        return array_merge(['status' => 'complete'],$args);
    }
    /**
     * write code for post process the payment information
     * @method runPostPaymentProcessForLandlordWalletDepositSuccessPayment
     * @param $payment_data
     * */
    private function runPostPaymentProcessForLandlordWalletDepositSuccessPayment(array $payment_data)
    {
//        dd($payment_data);
        if (isset($payment_data['status']) && $payment_data['status'] === 'complete'){
            $order_id = $payment_data['order_id'];
            $history_id = $payment_data["history_id"];
            $this->walletDepositUpdateDatabase($order_id, $payment_data['transaction_id'],$history_id);
            $this->walletDepositSendMailToAdmin($order_id);
            $new_order_id =  $order_id;
            return redirect()->to(route('landlord.user.wallet.history'))->with(['type' => 'success', 'msg' => 'Your wallet successfully credited']);
        }
    }
    /**
     * write code for post process the payment information for wallet balance update
     * @method walletDepositUpdateDatabase
     * @param mixed $order_id, mixed $transaction_id, mixed $history_id
     * */
    private function walletDepositUpdateDatabase(mixed $order_id, mixed $transaction_id, mixed $history_id)
    {
        $deposit_details = WalletHistory::find($history_id);

        DB::beginTransaction();
        try {
            WalletHistory::where('id', $history_id)->update([
                'payment_status' => 'complete',
                'transaction_id' => $transaction_id,
                'status' => 1,
            ]);

            $get_balance_from_wallet = Wallet::where('user_id',$deposit_details->user_id)->first();
            Wallet::where('user_id', $deposit_details->user_id)->update([
                'balance' => $get_balance_from_wallet->balance + $deposit_details->amount,
            ]);

            WalletService::check_wallet_balance($deposit_details->user_id);

            DB::commit();
        } catch (\Exception $exception) {
            DB::rollBack();
            //return redirect()->route('landlord.user.wallet.history')->with(FlashMsg::explain('danger', 'Something went wrong. Please try again after some while'));
        }
    }

    /**
     * write code for post process the sending mail to admin about wallet recharge by users
     * @method walletDepositSendMailToAdmin
     * @param $last_deposit_id
     * */
    public function walletDepositSendMailToAdmin($last_deposit_id)
    {
        if(empty($last_deposit_id)){
            return;
        }
        //Send order email to buyer
        try {
            $message_body = __('Hello an user just deposit to his wallet.').'</br>'.'<span class="verify-code">'.__('Deposit ID: ').$last_deposit_id.'</span>';
            \Mail::to(get_static_option('site_global_email'))->send(new BasicMail($message_body, __('Deposit Confirmation')));

        } catch (\Exception $e) {
            //
        }
    }
    /**
     * write code for post process the payment data for tenant shop checkout
     * @method runPostPaymentProcessForTenantdShopCheckoutSuccessPayment
     * @param $payment_data
     * */
    private function runPostPaymentProcessForTenantdShopCheckoutSuccessPayment(array $payment_data)
    {
        if (isset($payment_data['status']) && $payment_data['status'] === 'complete') {
            $this->TenantShopCheckoutSendOrderMail($payment_data['order_id']);
            $order_id = wrap_random_number($payment_data['order_id']);
            ProductOrder::find($payment_data['order_id'])->update([
                'payment_status' => 'success'
            ]);

            Cart::instance("default")->destroy();
            //todo

        }

    }
    /**
     * write code for post process the payment data for sending mail to admin and user about the product orders
     * @method TenantShopCheckoutSendOrderMail
     * @param $order_id
     * */
    private function TenantShopCheckoutSendOrderMail(mixed $order_id)
    {
        $order_details = ProductOrder::where('id', $order_id)->firstOrFail();
        $order_mail = get_static_option('order_page_form_mail') ?? get_static_option('tenant_site_global_email');

        try {
            //To User/Customer
            if ($order_details->checkout_type === 'digital')
            {
                Mail::to($order_mail)->send(new ProductOrderEmail($order_details));
            } else {
                Mail::to($order_mail)->send(new ProductOrderManualEmail($order_details));
            }

            // To Admin
            $admin_email = get_static_option('order_receiving_email') ?? get_static_option('tenant_site_global_email');
            if ($admin_email == null)
            {
                $admin = \App\Models\Admin::whereHas("roles", function($q){
                    $q->where("name", "Super Admin");
                })->first();
                $admin_email = $admin->email;
            }

            Mail::to($admin_email)->send(new ProductOrderEmailAdmin($order_details));

        } catch (\Exception $e) {

        }
    }

}
