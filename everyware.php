<?php
/*
 * Plugin Name: WooCommerce Everyware Payment Gateway
 * Plugin URI: https://everyware.com
 * Description: Secure card payment processing for your WooComerce store, powered by Everyware.
 * Author: Everyware
 * Author URI: https://everyware.com
 * Version: 1.0.2
 */

if (!in_array("woocommerce/woocommerce.php", apply_filters("active_plugins", get_option("active_plugins")))) return;

add_filter( "woocommerce_payment_gateways", "everyware_add_gateway_class" );
function everyware_add_gateway_class( $gateways ) {
	$gateways[] = "WC_Everyware_Gateway";

	return $gateways;
}

add_action( "plugins_loaded", "everyware_init_gateway_class" );
function everyware_init_gateway_class() {

	class WC_Everyware_Gateway extends WC_Payment_Gateway {

		public function __construct() {

			$this->id 					= "everyware";
			//$this->icon 				= plugins_url("assets/logo.png", __FILE__);
			$this->has_fields         	= true;
			$this->method_title       	= "Everyware";
			$this->method_description 	= "Custom solution to Accept credit and debit card payments online via Everyware payment gateway"; // will be displayed on the options page

			$this->supports = array(
				"products",
				"refunds",
				"default_credit_card_form"
			);

			$this->init_form_fields();

			$this->init_settings();
			$this->title       	= $this->get_option("title");
			$this->description 	= $this->get_option("description");
			$this->enabled     	= $this->get_option("enabled");
			$this->ew_username	= $this->get_option("ew_username");
			$this->ew_token		= $this->get_option("ew_token");
		    $this->base_url = "https://rest.everyware.com/api/Default/";

			if (is_admin()) {
                add_action("woocommerce_update_options_payment_gateways_" . $this->id, array(
                    $this,
                    "process_admin_options"
                ));
            }

			add_action( "wp_enqueue_scripts", array( $this, "payment_gateway_scripts" ) );
			add_action( "woocommerce_order_status_changed", array( $this, "order_status_refunded" ), 10, 3 );

		}
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
		    $transactionid = get_post_meta( $order_id, 'transactionid', true );
		    $is_refunded = get_post_meta( $order_id, 'is_refunded', true );
			if( $is_refunded != 'yes' && $transactionid ):

		    $order = wc_get_order($order_id);

		    $end_point = "CreateRefund";
			$arr   = array(
				"RefundType" => "full",
				"Amount" => $amount,
				"OrderNumber" => $order_id,
				"InvoiceID" => $transactionid
			);
		    $response = $this->call_api( $end_point, $arr );
			
			if ( $response->IsSuccess ) {
				update_post_meta($order_id, 'is_refunded', 'yes');
				$order->add_order_note( "Order has been refunded.", true );
				if(!empty($reason))
					$order->add_order_note( "Refund Reason: ".$reason, true );
				return true;
			} else {
				$order->add_order_note( $response->Message, true );
			}

			endif;
			return false;
		}

		function order_status_refunded($order_id, $old_status, $new_status)
		{
		    $is_refunded = get_post_meta( $order_id, 'is_refunded', true );
			if( $new_status == 'refunded' ):
		    $order = wc_get_order($order_id);
			$this->process_refund( $order_id, $order->get_total() );
			endif;
		}

		public function call_api( $end_point, $arr ) {
		    $url = $this->base_url.$end_point;

			$curl = curl_init();
			curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 0 );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, 0 );
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt_array( $curl, array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING       => "",
				CURLOPT_MAXREDIRS      => 10,
				CURLOPT_TIMEOUT        => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST  => "POST",
				CURLOPT_POSTFIELDS     => json_encode( $arr ),
				CURLOPT_HTTPHEADER     => array(
					"Authorization: Basic " . base64_encode( $this->ew_username . ":" . $this->ew_token ),
					"Content-Type: application/json"
				),
			) );

			$response = json_decode( curl_exec( $curl ) );

			curl_close( $curl );

			return $response;

		}

		public function init_form_fields() {

			$this->form_fields = array(
				"enabled"      => array(
					"title"       => "Enable/Disable",
					"label"       => "Enable Everyware Gateway",
					"type"        => "checkbox",
					"description" => "",
					"default"     => "no"
				),
				"title"        => array(
					"title"       => "Title",
					"type"        => "text",
					"description" => "This controls the title which the user sees during checkout.",
					"default"     => "Pay with Card",
					"desc_tip"    => true,
				),
				"description"  => array(
					"title"       => "Description",
					"type"        => "textarea",
					"description" => "This controls the description which the user sees during checkout.",
					"default"     => "Secure payment with your credit or debit card powered by Everyware.",
				),
				"ew_username" => array(
                    "title"       => "Everyware Username",
                    "type"        => "text",
                    "description" => "Please enter the username received from Everyware.",
                    "desc_tip"    => true,
                ),
                "ew_token" => array(
                    "title"       => "Everyware Token",
                    "type"        => "text",
                    "description" => "Please enter the token received from Everyware.",
                    "desc_tip"    => true,
                ),
			);
		}

		public function payment_fields() {

			$desc = "Secure Electronic Payment Directly From Your Checking Account.<span class='required'>*</span>";
			if ( $this->description ) {
				$desc = wpautop( wp_kses_post( $this->description ) );
			}
?>
			<?php do_action("woocommerce_credit_card_form_start", $this->id); ?>
			<fieldset id="wc-<?php echo esc_attr($this->id); ?>-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">
				<div class="form-row">
                    <label><?php echo $desc; ?></label>
					<div id="ew-card-holder" class="card-js">
						<input required aria-required="true" class="card-number my-custom-class" name="ew-card-number">
						<input required aria-required="true" class="expiry-month" name="ew-expiry-month">
						<input required aria-required="true" class="expiry-year" name="ew-expiry-year">
						<input required aria-required="true" class="cvc" name="ew-cvc">
					</div>
				</div>
				<div class="clear"></div>
				<script>
					(function(Card){
						Card.CardJs();
						Card.on("change","input.expiry",async function(){
							let expiry = Card.find("[class=expiry]").val(); 
							if(expiry.length >= 7 && !expiry.match('^[0-9]{2}\s*\/\s*[0-9]{2}$')){
								Card.find("[name=ew-expiry-month]").val("").change();
								Card.find("[name=ew-expiry-year]").val("").change();
								Card.find("[class=expiry]").val("");
								/* Card.find("[name=ew-expiry-month]").val(expiry.split("/")[0]);
								Card.find("[name=ew-expiry-year]").val(expiry.split("/")[1]); */
							}
						});
					})(jQuery("#ew-card-holder"))
				</script>
				<style>
					fieldset#wc-<?php echo esc_attr($this->id); ?>-cc-form{
						padding:0!important;
					}
				</style>
			</fieldset>
			<?php do_action("woocommerce_credit_card_form_end", $this->id); ?>

<?php	
		}

		public function payment_gateway_scripts() {
			
			wp_register_style("everyware_cc_styles", plugins_url("assets/card.css", __FILE__));
			wp_register_script("everyware_cc_scripts", plugins_url( "assets/card.js", __FILE__ ), array( "jquery" ) );
			wp_enqueue_style("everyware_cc_styles");
			wp_enqueue_script("everyware_cc_scripts");

			if (!is_cart() && !is_checkout() && !isset($_GET["pay_for_order"])) {
                wc_add_notice("Invalid page.", "error");
                return array("result" => "failed");
            }
            if ("no" === $this->enabled) {
                wc_add_notice("Payment mode disabled.", "error");
                return array("result" => "failed");
            }
            if (empty($this->ew_username) || empty($this->ew_token)) {
                wc_add_notice("Credentials missing.", "error");
                return array("result" => "failed");
            }
            if (!is_ssl()) {
                wc_add_notice("Page is unsecure. Payment gateway requires SSL to be turned on.", "error");
                return array("result" => "failed");
            }

		}

		public function validate_fields() {
			if ( empty( $_POST["ew-card-number"] ) ) {
				wc_add_notice( "Card Number is required!", "error" );
				return false;
			}
			if ( empty( $_POST["ew-expiry-month"] ) ) {
				wc_add_notice( "Expiry month is required!", "error" );
				return false;
			}
			if ( empty( $_POST["ew-expiry-year"] ) ) {
				wc_add_notice( "Expiry year is required!", "error" );
				return false;
			}
			if ( empty( $_POST["ew-cvc"] ) ) {
				wc_add_notice( "CVC/CVV is required!", "error" );
				return false;
			}
			return true;
		}

		public function process_payment( $order_id ) {
			global $woocommerce;

			$order = wc_get_order( $order_id );
		    $end_point = "CreatePayment";
			$arr   = array(
				"FirstName"      	=> $order->billing_first_name,
				"LastName"       	=> $order->billing_last_name,
				"Address1"       	=> $order->get_billing_address_1(),
				"Address2"       	=> $order->get_billing_address_2(),
				"City"           	=> $order->get_billing_city(),
				"StateCode"      	=> $order->get_billing_state(),
				"PostalCode"     	=> $order->get_billing_postcode(),
				"CountryCode"    	=> $order->get_billing_country(),
				"Email"          	=> $order->get_billing_email(),
				"MobilePhone"    	=> "",
				"Amount"         	=> $order->get_total(),
				"Description"    	=> "",
				"IsEmailReceipt" 	=> false,
				"IsSMSReceipt"   	=> false,
				"CreateToken"    	=> false,
				"OrderNumber"    	=> $order_id,
				"ChargeType"    	=> "Charge",
				"CCNumber"			=> str_replace(" ","",$_POST["ew-card-number"]),
				"ExpirationYear"    => $_POST["ew-expiry-month"],
				"ExpirationMonth"   => $_POST["ew-expiry-year"],
				"CVV"    			=> $_POST["ew-cvc"],
			);

		    $response = $this->call_api( $end_point, $arr );
			
			if ( $response->IsSuccess ) {
				update_post_meta($order_id, "transactionid", $response->Data->InvoiceID);
				wc_reduce_stock_levels( $order_id );
				$woocommerce->cart->empty_cart();

				$order->payment_complete();
				$order->add_order_note( "Hey, your payment has been received! We will process your order soon. Thank you!", true );

				return array(
					"result"   => "success",
					"redirect" => $order->get_checkout_order_received_url(),
				);
			} else {
				wc_add_notice( $response->Message, "error" );
				$order->add_order_note( $response->Message, true );
				return;
			}
		}
	}
}
