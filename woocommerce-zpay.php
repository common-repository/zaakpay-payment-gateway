<?php include('checksum.php'); ?>
<?php

/*
  Plugin Name: Zaakpay Payment Gateway
  Plugin URI: https://developer.zaakpay.com/docs/woocommerce-wordpress-payment-gateway-kit
  Description: Zaakpay is an indian payment which will accept the payment from any types of credit and debit card .
  Version: 1.0.0
  Author: Zaakpay
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

add_action('plugins_loaded', 'woocommerce_zaakpay_init', 0);
define('zaakpay_imgdir', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/');
define('WC_ZAAKPAY_PAYMENT_PLUGIN_TOKEN', 'wc-zaakpay-payment');
define('WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN', 'zaakpay_payment');
define('WC_ZAAKPAY_PAYMENT_PLUGIN_VERSION', '2.0.0');

function woocommerce_zaakpay_fallback_notice() {
    echo '<div class="error"><p>' . sprintf(__('WooCommerce Zaakpay Payment Gateways depends on the last version of %s to work!', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN), '<a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a>') . '</p></div>';
}

function woocommerce_zaakpay_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'woocommerce_zaakpay_fallback_notice');
        return;
    }

    if (isset($_GET['msg']) && !empty($_GET['msg'])) {
        add_action('the_content', 'zaakpay_showMessage');
    }

    function zaakpay_showMessage($content) {
        return '<div class="' . htmlentities($_GET['type']) . '">' . htmlentities(urldecode($_GET['msg'])) . '</div>' . $content;
    }

    /**
     * Payment Gateway class
     */
    class WC_zpay extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'zpay';
            $this->method_title = 'ZaakPay Payment Gateway';
            $this->method_description = __("This plugin no need any SSL it will redirect to the zaakpay secure hosted page and will return after payment It is only accept the INR ", WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN);
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            if ($this->settings['showlogo'] == "yes") {
                $this->icon = zaakpay_imgdir . 'logo.png';
            }
            foreach ($this->settings as $setting_key => $value) {
                $this->$setting_key = $value;
            }



            add_action('init', array(&$this, 'check_zpay_response'));
            //update for woocommerce >3.0
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_zpay_response'));

            if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>=')) {
                /* 2.0.0 */
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                /* 1.6.6 */
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }

            add_action('woocommerce_receipt_zpay', array(&$this, 'receipt_page'));

            $this->secretKey = $this->secret;

            add_action('woocommerce_api_zaakpaysuccess', array($this, 'zaakpay_success'));
        }

        function init_form_fields() {
            global $woocommerce;
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Zaakpay Payment Gateway.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'default' => 'no',
                    'description' => __('Show in the Payment List as a payment option', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN)
                ),
                'max_amount' => array(
                    'title' => __('Max Amount by this gateway:', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'text',
                    'default' => __('20000', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'description' => __('Please set the maximum amount can be proceed with this gateway', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'desc_tip' => true
                ),
                'title' => array(
                    'title' => __('Title:', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'text',
                    'default' => __('Zaakpay Payments', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'description' => __('This controls the title which the user sees during checkout.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'desc_tip' => true
                ),
                'availability' => array(
                    'title' => __('Countries (Method Availability)', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'select',
                    'default' => 'all',
                    'class' => 'availability',
                    'options' => array(
                        'all' => __('All Countries', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        'specific' => __('Specific Countries', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    ),
                ),
                'countries' => array(
                    'title' => __('Choose Countries', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'multiselect',
                    'class' => 'chosen_select',
                    'css' => 'width: 450px;',
                    'default' => '',
                    'options' => $woocommerce->countries->get_allowed_countries(),
                ),
                'description' => array(
                    'title' => __('Description:', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'textarea',
                    'default' => __('Pay securely by Credit or Debit Card through Zaakpay.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'description' => __('This controls the description which the user sees during checkout.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'desc_tip' => true
                ),
                'merchantIdentifier' => array(
                    'title' => __('Merchant Identifier', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'text',
                    'description' => __('Given to Merchant by Zaakpay', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'desc_tip' => true
                ),
                'secret' => array(
                    'title' => __('Merchant Key', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'text',
                    'description' => __('Given to Merchant by Zaakpay', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'desc_tip' => true
                ),
                'showlogo' => array(
                    'title' => __('Show Logo', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Show the Zaakpay logo in the Payment Method section for the user', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'default' => 'yes',
                    'description' => __('Tick to show Zaakpay logo', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'desc_tip' => true
                ),
                'mode' => array(
                    'title' => __('Choose the Payment Mode', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'select',
                    'class' => 'select required',
                    'css' => 'width:250px;',
                    'desc_tip' => __('Choose transaction type live or sandbox.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'default' => '',
                    'options' => array(
                        '' => __('Please Select', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        '0' => __('Staging', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        '1' => __('Live', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN)
                    )
                ),
                'purpose' => array(
                    'title' => __('Purpose', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'select',
                    'class' => 'select required',
                    'css' => 'width:250px;',
                    'desc_tip' => __('Choose the purpose to using this gateway.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'default' => '',
                    'options' => array(
                        '' => __('Please Select', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        '0' => __('Service', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        '1' => __('Goods', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        '2' => __('Auction', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        '3' => __('Others', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN)
                    )
                ),
                'log' => array(
                    'title' => __('Choose log mode', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'type' => 'select',
                    'class' => 'select required',
                    'css' => 'width:250px;',
                    'desc_tip' => __('Choose log to be written or not.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                    'default' => '',
                    'options' => array(
                        '' => __('Please Select', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        'no' => __('disable', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN),
                        'yes' => __('enable', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN)
                    )
                ),
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         * */
        public function admin_options() {
            echo '<h3>' . __('ZaakPay Payment Gateway', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN) . '</h3>';
            echo '<p>' . __('ZaakPay Payment gateway is very simple to use with secure transaction', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN) . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields for zaakpay, but we want to show the description if set.
         * */
        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
            if ($this->mode != 1) {
                echo wpautop(wptexturize('<p>Zaakpay is in staging mode so please use the staging credentials. '));
            }
        }

        /**
         * Receipt Page
         * */
        function receipt_page($order) {
            echo '<p>' . __('Thank you for your order, please click the button below to pay with ZaakPay.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN) . '</p>';
            echo $this->generate_zaakpay_form($order);
        }

        // get all pages
        function zaakpay_get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title)
                $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_post($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

        /**
         * web redirect redirected to the chhosen return page by javascript function.
         *
         */
        public function web_redirect($url) {
            echo "<html><head><script language=\"javascript\">
				<!--
				window.location=\"{$url}\";
				//-->
				</script>
				</head><body><noscript><meta http-equiv=\"refresh\" content=\"0;url={$url}\"></noscript></body></html>";
        }

        /**
         * Check if the gateway is available for use
         *
         * @return bool
         */
        public function is_available() {
            $is_available = ( 'yes' === $this->enabled ) ? true : false;
            if (WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total()) {
                $is_available = false;
            }
            $store_currency = get_woocommerce_currency();
            if ($store_currency != 'INR') {
                wc_print_notice("Only INR Supported by zaakpay payment gateway, please change the store currency to INR ", 'notice');
                $is_available = false;
            }
            if ('specific' === $this->availability) {
                if (is_array($this->countries) && !in_array($package['destination']['country'], $this->countries)) {
                    $is_available = false;
                }
            }
            return $is_available;
        }

        /**
         * Generate payu button link
         * */
        function generate_zaakpay_form($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);
            $redirect_url = $order->get_checkout_order_received_url();
            $redirect_url .= "&wc-api=" . get_class($this);

            error_log("redirect url = this {$redirect_url}");

            $txnDate = date('Y-m-d');
            $amt = (int) (100 * ($order->order_total));
            $currency = "INR";
            $post_data_args = array(
                "merchantIdentifier" => $this->merchantIdentifier,
                "orderId" => $order_id,
                "returnUrl" => $redirect_url,
                "buyerEmail" => $order->billing_email,
                "buyerFirstName" => $order->billing_first_name,
                "buyerLastName" => $order->billing_last_name,
                "buyerAddress" => $order->billing_address_1,
                "buyerCity" => $order->billing_city,
                "buyerState" => $order->billing_state,
                "buyerCountry" => $order->billing_country,
                "buyerPincode" => $order->billing_postcode,
                "buyerPhoneNumber" => $order->billing_phone,
                "txnType" => 1,
                "zpPayOption" => 1,
                "mode" => $this->mode,
                "currency" => $currency,
                "amount" => $amt,
                "merchantIpAddress" => $_SERVER['REMOTE_ADDR'],
                "purpose" => $this->purpose,
                "productDescription" => $order_id,
                "txnDate" => $txnDate
            );

            error_log("Product Description : " . $post_data_args['productDescription']);

            $all = '';

            if ($this->mode == 1) {
                foreach ($post_data_args as $key => $value) {

                    if ($key != 'checksum') {
                        $all .= "'";
                        if ($key == 'returnUrl') {
                            $all .= Checksum::sanitizedURL($value);
                        } else {
                            $all .= Checksum::sanitizedParam($value);
                        }
                        $all .= "'";
                    }
                    $checksum = Checksum::calculateChecksum($this->secret, $all);
                }

                if ($this->log == "yes") {
                    error_log("AllParams : " . $all);
                    error_log("Secret Key : " . $this->secret);
                    error_log("Checksum : " . $checksum);
                }


                $data_args = array(
                    "merchantIdentifier" => $this->merchantIdentifier,
                    "orderId" => $order_id,
                    "returnUrl" => $redirect_url,
                    "buyerEmail" => $order->billing_email,
                    "buyerFirstName" => $order->billing_first_name,
                    "buyerLastName" => $order->billing_last_name,
                    "buyerAddress" => $order->billing_address_1,
                    "buyerCity" => $order->billing_city,
                    "buyerState" => $order->billing_state,
                    "buyerCountry" => $order->billing_country,
                    "buyerPincode" => $order->billing_postcode,
                    "buyerPhoneNumber" => $order->billing_phone,
                    "txnType" => 1,
                    "zpPayOption" => 1,
                    "mode" => $this->mode,
                    "currency" => $currency,
                    "amount" => $amt,
                    "merchantIpAddress" => $_SERVER['REMOTE_ADDR'],
                    "purpose" => $this->purpose,
                    "productDescription" => $order_id,
                    "txnDate" => $txnDate,
                    "checksum" => $checksum
                );
                $data_args_array = array();
                foreach ($data_args as $key => $value) {
                    if ($key != 'checksum') {
                        if ($key == 'returnUrl') {
                            $data_args_array[] = "<input type='hidden' name='$key' value='" . Checksum::sanitizedURL($value) . "'/>";
                        } else {
                            $data_args_array[] = "<input type='hidden' name='$key' value='" . Checksum::sanitizedParam($value) . "'/>";
                        }
                    } else {
                        $data_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
                    }
                }
            } else {

                $checksumsequence = array("amount", "bankid", "buyerAddress",
                    "buyerCity", "buyerCountry", "buyerEmail", "buyerFirstName", "buyerLastName", "buyerPhoneNumber", "buyerPincode",
                    "buyerState", "currency", "debitorcredit", "merchantIdentifier", "merchantIpAddress", "mode", "orderId",
                    "product1Description", "product2Description", "product3Description", "product4Description",
                    "productDescription", "productInfo", "purpose", "returnUrl", "shipToAddress", "shipToCity", "shipToCountry",
                    "shipToFirstname", "shipToLastname", "shipToPhoneNumber", "shipToPincode", "shipToState", "showMobile", "txnDate",
                    "txnType", "zpPayOption");

                foreach ($checksumsequence as $seqvalue) {
                    if (array_key_exists($seqvalue, $post_data_args)) {
                        if ($seqvalue != 'checksum') {

                            if ($seqvalue == 'returnUrl') {
                                $all .= $seqvalue;
                                $all = $all . "=";
                                $all .= Checksum::sanitizedURL($post_data_args[$seqvalue]);
                            } else {

                                $all .= $seqvalue;
                                $all = $all . "=";
                                $all .= Checksum::sanitizedParam($post_data_args[$seqvalue]);
                            }
                            $all .= "&";
                        }
                    }

                    $checksum = Checksum::calculateChecksum($this->secret, $all);
                }


                if ($this->log == "yes") {
                    error_log("AllParams : " . $all);
                    error_log("Secret Key : " . $this->secret);
                    error_log("Checksum : " . $checksum);
                }


                $data_args = array(
                    "merchantIdentifier" => $this->merchantIdentifier,
                    "orderId" => $order_id,
                    "returnUrl" => $redirect_url,
                    "buyerEmail" => $order->billing_email,
                    "buyerFirstName" => $order->billing_first_name,
                    "buyerLastName" => $order->billing_last_name,
                    "buyerAddress" => $order->billing_address_1,
                    "buyerCity" => $order->billing_city,
                    "buyerState" => $order->billing_state,
                    "buyerCountry" => $order->billing_country,
                    "buyerPincode" => $order->billing_postcode,
                    "buyerPhoneNumber" => $order->billing_phone,
                    "txnType" => 1,
                    "zpPayOption" => 1,
                    "mode" => $this->mode,
                    "currency" => $currency,
                    "amount" => $amt,
                    "merchantIpAddress" => $_SERVER['REMOTE_ADDR'],
                    "purpose" => $this->purpose,
                    "productDescription" => $order_id,
                    "txnDate" => $txnDate,
                    "checksum" => $checksum,
                    "allstring" => $all
                );
                $data_args_array = array();
                foreach ($data_args as $key => $value) {
                    if ($key != 'checksum') {
                        if ($key == 'returnUrl') {
                            $data_args_array[] = "<input type='hidden' name='$key' value='" . Checksum::sanitizedURL($value) . "'/>";
                        } elseif ($key == 'allstring') {
                            $data_args_array[] = "<input type='hidden' name='$key' value='" . ($value) . "'/>";
                        } else {
                            $data_args_array[] = "<input type='hidden' name='$key' value='" . Checksum::sanitizedParam($value) . "'/>";
                        }
                    } else {
                        $data_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
                    }
                }
            }

            if ($this->mode == 1) {

                $this->liveurl = "https://api.zaakpay.com/transact?v=3";
            } else {
                $this->liveurl = "https://zaakstaging.zaakpay.com/api/paymentTransact/V7#";
            }

            return '	<form action="' . $this->liveurl . '" method="post" id="zaakpay_payment_form">
  				' . implode('', $data_args_array) . '
				<input type="submit" class="button-alt" id="submit_zaakpay_payment_form" value="' . __('Pay via ZaakPay', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN) . '</a>
					<script type="text/javascript">
					jQuery(function(){
					jQuery("body").block({
						message: "' . __('Thank you for your order. We are now redirecting you to Payment Gateway to make payment.', WC_ZAAKPAY_PAYMENT_TEXT_DOMAIN) . '",
						overlayCSS: {
							background		: "#fff",
							opacity			: 0.6
						},
						css: {
							padding			: 20,
							textAlign		: "center",
							color			: "#555",
							border			: "3px solid #aaa",
							backgroundColor	: "#fff",
							cursor			: "wait",
							lineHeight		: "32px"
						}
					});
					jQuery("#submit_zaakpay_payment_form").click();});
					</script>
				</form>';
        }

        public function zaakpay_success() {
            if(!$this->json_validator($_REQUEST['txnData'])) {
                die("Invalid JSON format");
            }
            $jstr = $_REQUEST['txnData'];
            $json = str_replace("\\", "", $jstr);
            $obj = json_decode($json);
            $data = $obj->txns;
            $txn = json_encode($data[0]);
            $transaction = json_decode($txn);

            $checksum = Checksum::calculateChecksum($this->secretKey, $json);

            error_log("Checksum calculated : " . $checksum);

            // Added to make sure checksum always in alphanumeric format - 965017f62405ef10cbd41a993793c27a08f55dccd7b6ab0e38146a842dc21902
            $receivedChecksum = ""; 
            if(ctype_alnum($_REQUEST['checksum'])) 
                $receivedChecksum = $_REQUEST['checksum'];
            else 
                die("Received checksum not alphanumeric!");
            
            error_log("Checksum received : " . $receivedChecksum);

            //Added to handle realtime or non-realtime success
            
            $realtime = NULL ;
           	$realtime = filter_var($_REQUEST['?realtime'], FILTER_VALIDATE_BOOLEAN);

			$receivedOrderId = NULL ;           	
            if($realtime == true)
            	$receivedOrderId = $transaction->orderId ;
            else
            	$receivedOrderId = $transaction->orderid ;


            error_log("Processing zaakpay response for orderId : " . $receivedOrderId);


            if (($transaction->responseCode == 100 and $checksum == $receivedChecksum) or (!$realtime and $checksum == $receivedChecksum)) {
                error_log("Completed payment for orderid : " . $receivedOrderId);

                $order = new WC_Order($receivedOrderId) ;
                $order->update_status('processing', __('Zaakpay Response'));
                error_log("Successfully Updated");
                
            } else {
                error_log("Transaction can not be updated");
            }
        }
        
        function json_validator($json=NULL) {
            $data = str_replace("\\", "", $json);
            if (!empty($data)) {
                @json_decode($data);
                return (json_last_error() === JSON_ERROR_NONE);
        }
        return false;
}

        private function sanitizedParam($param) {
            $pattern[0] = "%,%";
            $pattern[1] = "%#%";
            $pattern[2] = "%\(%";
            $pattern[3] = "%\)%";
            $pattern[4] = "%\{%";
            $pattern[5] = "%\}%";
            $pattern[6] = "%<%";
            $pattern[7] = "%>%";
            $pattern[8] = "%`%";
            $pattern[9] = "%!%";
            $pattern[10] = "%\\$%";
            $pattern[11] = "%\%%";
            $pattern[12] = "%\^%";
            $pattern[13] = "%=%";
            $pattern[14] = "%\+%";
            $pattern[15] = "%\|%";
            $pattern[16] = "%\\\%";
            $pattern[17] = "%:%";
            $pattern[18] = "%'%";
            $pattern[19] = "%\"%";
            $pattern[20] = "%;%";
            $pattern[21] = "%~%";
            $pattern[22] = "%\[%";
            $pattern[23] = "%\]%";
            $pattern[24] = "%\*%";
            $pattern[25] = "%&%";
            $sanitizedParam = preg_replace($pattern, "", $param);
            return $sanitizedParam;
        }

        private function sanitizedURL($param) {
            $pattern[0] = "%,%";
            $pattern[1] = "%\(%";
            $pattern[2] = "%\)%";
            $pattern[3] = "%\{%";
            $pattern[4] = "%\}%";
            $pattern[5] = "%<%";
            $pattern[6] = "%>%";
            $pattern[7] = "%`%";
            $pattern[8] = "%!%";
            $pattern[9] = "%\\$%";
            $pattern[10] = "%\%%";
            $pattern[11] = "%\^%";
            $pattern[12] = "%\+%";
            $pattern[13] = "%\|%";
            $pattern[14] = "%\\\%";
            $pattern[15] = "%'%";
            $pattern[16] = "%\"%";
            $pattern[17] = "%;%";
            $pattern[18] = "%~%";
            $pattern[19] = "%\[%";
            $pattern[20] = "%\]%";
            $pattern[21] = "%\*%";
            $sanitizedParam = preg_replace($pattern, "", $param);
            return $sanitizedParam;
        }

        private function verifyChecksum($checksum, $all, $secret) {
            $cal_checksum = $this->calculateChecksum($secret, $all);
            $bool = 0;
            if ($checksum == $cal_checksum) {
                $bool = 1;
            }
            return $bool;
        }

        private function calculateChecksum($secret_key, $all) {
            $hash = hash_hmac('sha256', $all, $secret_key);
            $checksum = $hash;
            return $checksum;
        }

        /**
         * Process the payment and return the result
         * */
        function process_payment($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url(true);
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink(get_option('woocommerce_pay_page_id'));
            }

            return array(
                'result' => 'success',
                'redirect' => esc_url(add_query_arg(
                                'order',
                                $order->id,
                                add_query_arg(
                                        'key',
                                        $order->order_key,
                                        $checkout_payment_url
                                )
                        )
            ));
        }

        /**
         * Check for valid payu server callback
         * */
        function check_zpay_response() {
            global $woocommerce;
            if (isset($_REQUEST['orderId']) && isset($_REQUEST['responseCode'])) {            
                //sanitized for normal string order id 
                $order_sent = filter_var($_REQUEST['orderId'], FILTER_SANITIZE_STRING, array('flags' => FILTER_NULL_ON_FAILURE));
                $responseDescription = filter_var($_REQUEST['responseDescription'], FILTER_SANITIZE_STRING, array('flags' => FILTER_NULL_ON_FAILURE));
                if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                    $order = new WC_Order($order_sent);
                } else {
                    $order = new woocommerce_order($order_sent);
                }
                $response_code = filter_var($_REQUEST['responseCode'], FILTER_SANITIZE_STRING, array('flags' => FILTER_NULL_ON_FAILURE));
                if ($this->log == "yes") {
                    error_log("Response Code = " .$response_code );
                }
                $redirect_url = $order->get_checkout_order_received_url();

                $this->msg['class'] = 'error';
                $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been Failed For Reason  : " . $responseDescription;
                if ($response_code == 100) { // success							
                    if ($this->log == "yes") {
                        error_log("Order Id " . $order_sent . "Completed successfully");
                    }
                    if ($order->status !== 'completed') {
                        error_log("SUCCESS");
                        $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful.";
                        $this->msg['class'] = 'success';
                        if ($order->status == 'processing') {
                            $order->add_order_note('Please check the payment status before the ');
                            $woocommerce->cart->empty_cart();
                        } else {
                            $order->payment_complete();
                            $order->add_order_note('Zaakpay payment successful');
                            $order->add_order_note($this->msg['message']);
                            $woocommerce->cart->empty_cart();
                        }
                    } else {
                        $this->msg['class'] = 'error';
                        $this->msg['message'] = "Severe Error Occur.";
                        $order->update_status('failed');
                        $order->add_order_note('Failed');
                        $order->add_order_note($this->msg['message']);
                    }
                } else {
                    $order->update_status('failed');
                    $order->add_order_note('Failed');
                    $order->add_order_note($responseDescription);
                    $order->add_order_note($this->msg['message']);
                }

                add_action('the_content', array(&$this, 'showMessage'));

                $redirect_url = add_query_arg(array('msg' => urlencode($this->msg['message']), 'type' => $this->msg['class']), $redirect_url);
                wp_redirect($redirect_url);
                exit;
            }
        }

    }

    /**
     * Add the Gateway to WooCommerce
     * */
    function woocommerce_add_zpay_gateway($methods) {
        $methods[] = 'WC_zpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_zpay_gateway');
}
