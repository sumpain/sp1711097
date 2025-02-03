<?php 
/*
 *
 * =======================
 *
 * WOOCOMMERCE - Constants
 *
 * =======================
 *
 */
define( "FLAT_SHIPPING_RATE_MIN", 8 );
define( "FLAT_SHIPPING_RATE_MAX", 12 );
define( "ENCAPSULATION_A0", 18 );
define( "ENCAPSULATION_A1", 10 );
define( "ENCAPSULATION_A2", 8 );
define( "ENCAPSULATION_A3", 5 );
define( "ENCAPSULATION_A4", 3 );
define( "DELIVERY_MIN", 12 );
define( "DELIVERY_MIN_THRESHOLD", 5 );

/*
 *
 * ===================================================================
 *
 * WOOCOMMERCE
 * Set init cookie (for non logged user)
 * used when in backend you retrieve the number of pages for each file
 *
 * ===================================================================
 *
 */
function set_init_cookie() 
{
	if ( !isset( $_COOKIE[ 'woo_custom_cookie' ] )) 
	{
		setcookie( 'woo_custom_cookie', md5(uniqid(date("Ymdhis"))), strtotime( '+1 day' ));
	}
}
add_action( 'init', 'set_init_cookie' );

/*function deleteCustomCookie()
{
	setcookie( 'woo_custom_cookie', null, strtotime( '-1 day' ));
}*/

/*
 *
 * ===================================================================
 *
 * WOOCOMMERCE - Get custom Token
 *
 * ===================================================================
 *
*/
function getCustomtoken()
{
	$token = wp_get_session_token();
	
	if( !empty( $token ))
	{
		return $token;
	}
	else {
		return $_COOKIE[ 'woo_custom_cookie' ];
	}
}

/*
*
* ========================
* 
* WOOCOMMERCE CUSTOM COSTS
*
* ========================
* 
*/

/* add Delivery costs and Encapsulation costs */
function add_custom_fees( WC_Cart $cart ) {
	global $flat_shipping_rate;
	global $woocommerce; 
    $shipping_packages = $woocommerce->cart->get_shipping_packages(); 
	$shipping_zone = wc_get_shipping_zone( reset( $shipping_packages ) );
	$zone_id   = $shipping_zone->get_id(); // Get the zone ID
	$zone_name = $shipping_zone->get_zone_name(); // Get the zone name
	$zone_order = $shipping_zone->get_zone_order();
	$zone_locations = $shipping_zone->get_zone_locations();
	$zone_formatted_location = $shipping_zone->get_formatted_location();
	$zone_shipping_methods = $shipping_zone->get_shipping_methods(); // SEE BELOW	
	
	$flat_shipping_rate = FLAT_SHIPPING_RATE_MIN;	
	$encapsulation      = 0;
	$encapsMult  		= 0;
	
	$flat_rate_settings = $woocommerce->customer->get_postcode();
	echo "<div class='asdasd' style='display:none'>";
	print_r($zone_name);
	echo "</div>";
	
	foreach ( $cart->get_cart() as $item )
	{
		$b_encapsulation = false;
		$arr_keys = array( '20.1', '24.1', '25.1' ); 
		foreach( $arr_keys as $key )
		{
			if( isset( $item['_gravity_form_lead'][ $key ] ))
			{
				if ( $item['_gravity_form_lead'][ $key ] == "Yes|0" )
				{
					$b_encapsulation = true;
					break;
				}
			}
		}
		
		/* customer selected "encapsulation" */
		if ( $b_encapsulation ) 
		{
			$size = $item['_gravity_form_lead']['9'];

			switch( $size )
			{
				case "A0|0":
					$encapsMult = ENCAPSULATION_A0;
					break;
				case "A1|0":
					$encapsMult = ENCAPSULATION_A1;
					break;
				case "A2|0":
					$encapsMult = ENCAPSULATION_A2;
					break;
				case "A3|0":
					$encapsMult = ENCAPSULATION_A3;
					break;
				default:
					$encapsMult = ENCAPSULATION_A4;
			}
			
			/* encapsulation * total pages */
			$encapsulation += $encapsMult * $item['_gravity_form_lead']['34.3'];
		}
		
		foreach ( $item['_gravity_form_lead'] as $prop ) 
		{
			if ( strpos( strtolower( $prop ), 'rolled') !== false ) 
			{				
					$flat_shipping_rate = FLAT_SHIPPING_RATE_MAX;	
			}
		}
	}

	if ( $encapsulation > 0 ) 
	{
		$cart->add_fee( __( 'Encapsulation costs' ), $encapsulation, true); /* TRICK: the true parameter allows to apply the VAT */
	}
	if(!empty($zone_name) && $zone_name == "Scotland & Highlands"){
		$cart->add_fee( __( 'Delivery costs' ), 35, false ); /* TRICK: the true parameter allows to apply the VAT */
	}else{
		$cart->add_fee( __( 'Delivery costs' ), $flat_shipping_rate, true ); /* TRICK: the true parameter allows to apply the VAT */
	}
}

add_action( 'woocommerce_cart_calculate_fees', 'add_custom_fees' );

/*
 If a customer places an order and the order including delivery is less than [POUND SIGN]10.00 then a small order surcharge is applied.
 The small order surcharge is the difference between the order amount and [POUND SIGN]10.00.

 E.g. Order [POUND SIGN]0.70, delivery [POUND SIGN]5.00, the total order would be [POUND SIGN]5.70 so [POUND SIGN]4.30 would be added to the order to make it meet the [POUND SIGN]10.00
 minimum spend and then VAT would be applied to this at the checkout.
 */

add_action( 'woocommerce_cart_calculate_fees', 'woocommerce_custom_surcharge' );

function woocommerce_custom_surcharge() {

	global $flat_shipping_rate;

	/*
	 * LOGIC:
	 * 1. sum the subtotal with the "delivery costs"
	 * 2. then, just in case of sum is lower than 10, I added the small order surcharge
	 * 
	 */ 
	global $woocommerce;
	
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) 
	{
		return;
	}

	$delivery_min = DELIVERY_MIN;

	/*
	 * Just a note if SUBTOTAL < DELIVERY_MIN_THRESHOLD (5):
	 * ----------------------------
	 * SUBTOTAL + DELIVERY(Shipping) = 12 (MINIMUN)
	 * +
	 * 2 pound VAT
	 * ==
	 * 12 pound DELIVERY_MIN
	 */
	if( $woocommerce->cart->cart_contents_total < DELIVERY_MIN_THRESHOLD )
	{
		$supposedSurCharge = DELIVERY_MIN_THRESHOLD - $woocommerce->cart->cart_contents_total;
		
		$woocommerce->cart->add_fee( __( 'Surcharge' ), $supposedSurCharge, true );
	}
	/* Waiting for the next change ... mod date: 2017/12/28 */
	/*else {
		$check = $woocommerce->cart->cart_contents_total + $flat_shipping_rate;
		
		if ( $check < $delivery_min ) 
		{
			$supposedSurCharge = $delivery_min - $check;
	
			$woocommerce->cart->add_fee( __( 'Surcharge' ), $supposedSurCharge, false );
		}
	}*/
}

/*
 *
 * ============================
 *
 * WOOCOMMERCE CUSTOM TEMPLATES
 *
 * ============================
 * 
 */
function woo_ajax_variation_threshold( $qty, $product ) 
{
	return 100;
}
add_filter( 'woocommerce_ajax_variation_threshold', 'woo_ajax_variation_threshold', 10, 2 );

function woo_change_return_shop_url() 
{
	return sprintf( '%s%s', get_site_url(), '/order-now/' );
}
add_filter( 'woocommerce_return_to_shop_redirect', 'woo_change_return_shop_url' );

/*
 *
 * ================================================================
 *
 * WOOCOMMERCE - Add option on db order_id -> session (or order id)
 *
 * ================================================================
 *
 */
function action_woocommerce_thankyou( $order_id )
{	
	$option = sprintf( '_woo_custom_order_id_x_session|o:%s', $order_id );
	
	$token = getCustomtoken();
	
	add_option( $option, $token );
}
add_action( 'woocommerce_thankyou', 'action_woocommerce_thankyou', 10, 1 );

/*
 *
 * ===============================================
 *
 * WOOCOMMERCE - Remove item in a woocommerce cart
 *
 * ===============================================
 *
 */
function woocommerce_remove_db_option( $cart_item_key, $cart ) 
{
	$product_id = $cart->cart_contents[ $cart_item_key ]['product_id'];

	$token = getCustomtoken();
	
	$keyOpt = sprintf( '_woo_custom_option_number_of_pages_x_file|t:%s', $token );
	
	$opt = json_decode( get_option( $keyOpt ));
	
	foreach( $opt->files as $f )
	{
		unlink ( '/gravity_forms/4-d6fa5fdf80a8c519adac83ff0de7bf08/tmp/' . $f );
	}
	
	delete_option( $keyOpt );
};
add_action( 'woocommerce_remove_cart_item', 'woocommerce_remove_db_option', 10, 2 );

/*
 *
 * ===========================================
 *
 * WOOCOMMERCE - Custom Message on add to cart
 *
 * ===========================================
 *
 */
function custom_add_to_cart_message()
{
	global $woocommerce;
	$message = "<p>Your printing project has been added to the cart.</p>";
	$message.= "<p><span><a class='fusion-button button-flat fusion-button-pill button-large button-custom button-1' href='".get_site_url()."/order-now/'><i class='fa fa-chevron-circle-left button-icon-left'></i><span class='fusion-button-text'>Add Another Project</span></a></span>";
	$message.= " <span><a class='fusion-button button-flat fusion-button-pill button-large button-custom button-1' href='".get_site_url()."/cart/'><i class='fa fa-shopping-cart button-icon-left'></i><span class='fusion-button-text'>View Cart</span></a></span></p>";

	return $message;
}
add_filter( 'wc_add_to_cart_message_html', 'custom_add_to_cart_message' );

/*
 * 
 * ===================================
 *
 * WOOCOMMERCE CUSTOM LOGIN / REGISTER
 *
 * ===================================
 * 
 */
function pp_login_message() {
	?>
    <p><?php echo __( 'We are pleased to announce the launch of our new website, we would like to take this opportunity thank all our returning customers for their ongoing business. Please register your details into our new site where they will be saved in preparation for your next order.' ); ?></p>
	<?php
}

add_action( 'woocommerce_before_customer_login_form', 'pp_login_message' );

/*
 *
 * ==================================================
 *
 * WOOCOMMERCE INJECTED THE POPUP TO CHECK OLD ORDERS
 *
 * ==================================================
 *
 */
function injected_popup_old_orders() {
	?>
    <div class="popup" data-popup="popup-orders">
	    <div class="popup-inner">
	    	
	    	<img src="https://www.printingandplotting.co.uk/wp-content/uploads/2017/08/logo-small-1aa.png" alt="Printing and Plotting" title="Printing and Plotting" />
	    	
	        <h2><?php echo __( 'Keeping a clean and up-to-date database and filesystem' ); ?></h2>
	        <p><?php echo __( 'Some orders are more than one month old. Old files and references on database and filesystem can reduce the performance. The deletion is pemanent.' ); ?></p>
	        <p><?php echo __( 'Click Remove to cleaning the system or Close to close this pop up.' ); ?></p>
	        
	        <p class="user_message">
	        	<span class=""><?php echo __( 'File deleted' ); ?></span>:<span class="number_file_deleted"></span>
	        	<br />
	        	<span class=""><?php echo __( 'Database rows deleted' ); ?></span>:<span class="number_options_deleted"></span>
	        </p>
	        
	        <button type="button" name="remove_old_orders" value="remove"><?php echo __( 'Remove' ); ?></button>
	        <p class="loading"><?php echo __( 'Loading...' ); ?></p>
	        
	        <p><a data-popup-close="popup-orders" href="#"><?php echo __( 'Close' ); ?></a></p>
	        <a class="popup-close" data-popup-close="popup-orders" href="#">x</a>
	    </div>
	</div>
	<?php
}
add_action( 'admin_footer', 'injected_popup_old_orders' );

function custom_admin_style() 
{
	?>
	<style>.popup{width:100%;height:100%;display:none;position:fixed;top:0;left:0;background:rgba(0,0,0,.75)}.popup-inner{max-width:700px;width:90%;padding:40px;position:absolute;top:50%;left:50%;-webkit-transform:translate(-50%,-50%);transform:translate(-50%,-50%);box-shadow:0 2px 6px rgba(0,0,0,1);border-radius:3px;background:#fff}.popup-close{width:30px;height:30px;padding-top:4px;display:inline-block;position:absolute;top:0;right:0;transition:ease .25s all;-webkit-transform:translate(50%,-50%);transform:translate(50%,-50%);border-radius:1000px;background:rgba(0,0,0,.8);font-family:Arial,Sans-Serif;font-size:20px;text-align:center;line-height:100%;color:#fff}.popup-close:hover{-webkit-transform:translate(50%,-50%) rotate(180deg);transform:translate(50%,-50%) rotate(180deg);background:rgba(0,0,0,1);text-decoration:none}.popup .user_message{display:none;}.popup .loading{display:none;}</style>
	<?php 
}
add_action( 'admin_head', 'custom_admin_style' );
