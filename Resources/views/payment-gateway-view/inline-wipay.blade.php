<script type="text/javascript">
    //if user select paddle then hide send a request to the server with all order info so that in can send users to paddle server for overlay checkout
    window.addEventListener("DOMContentLoaded", (event) => {
        @if(request()->is("plan-order*"))
            document.querySelector('.payment-gateway-wrapper li[data-gateway="wipay"]').style.display = "none";
        @endif
        @if(empty(get_static_option("wipay_status")))
            document.querySelector('.payment-gateway-wrapper li[data-gateway="wipay"]').style.display = "none";
        @endif 
    });
</script>
