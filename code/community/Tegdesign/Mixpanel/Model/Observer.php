<?php
class Tegdesign_Mixpanel_Model_Observer extends Mage_Core_Model_Abstract
{
    public $token;
    public $host = 'http://api.mixpanel.com/';
	
    public function __construct($token_string) {
        $this->token = Mage::getStoreConfig('tegdesign_mixpanel_options/settings/mixpanel_token');
    }

    public function trackAddToCart($observer)
    {

    	$event = $observer->getEvent();
        $product = $event->getProduct();
        $sku = $product->getSku();

        $cart = Mage::getSingleton('checkout/cart');

		$this->track('add_to_cart', array('sku'         => $sku,
                                          'cart_count'  => $cart->getItemsCount(),
                                          'cart_total'	=> $cart->getQuote()->getGrandTotal(),
                                          'distinct_id' => $this->getCustomerIdentity()));
    }

    public function trackReview($observer)
    {

        $event = $observer->getEvent();
        $action = $event->getControllerAction();
        $post_data = $action->getRequest()->getPost();

        if (isset($post_data['detail'])) {

            $this->track('customer_action', array('action'      => 'product_reviewed',
                                                  'nickname'    => $post_data['nickname'],
                                                  'title'       => $post_data['title'],
                                                  'distinct_id' => $this->getCustomerIdentity()));
        }
        
    }

    public function trackVote($observer)
    {

        $vote = $observer->getVote()->getData();
        $poll = $observer->getPoll()->getData();

        $this->track('customer_action', array('action'          => 'polled_voted',
                                              'poll_title'      => $poll['poll_title'],
                                              'poll_answer_id'  => $vote['poll_answer_id'],
                                              'distinct_id'     => $this->getCustomerIdentity()));

    }

    public function trackCustomerLogout($observer) {

        $this->track('customer_action', array('action'          => 'logout',
                                              'distinct_id'     => $this->getCustomerIdentity()));
    }

    public function trackCustomerLogin($observer) {

        $this->track('customer_action', array('action'          => 'login',
                                              'distinct_id'     => $this->getCustomerIdentity()));
    }

    public function trackNewsletter($observer)
    {

        $subscriber = $observer->getEvent()->getSubscriber();

        if($subscriber->getStatus() == Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED) {

            $this->track('customer_action', array('action'  => 'subscribed_to_newsletter', 'distinct_id' => $this->getCustomerIdentity()));

        } elseif($subscriber->getStatus() == Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED) {

            $this->track('customer_action', array('action'  => 'unsubscribed_from_newsletter', 'distinct_id' => $this->getCustomerIdentity()));

        }

    }

    public function getCustomerIdentity() {

    	if (Mage::getSingleton('customer/session')->isLoggedIn()) {

			$c = Mage::getSingleton('customer/session')->getCustomer();
			$customer = Mage::getModel('customer/customer')->load($c->getId());

    		$person = array();
	        $person = $this->getCustomerTrackInfo($customer);
    		$this->trackPerson($person);

			return $customer->getEmail();

		} else {

			return $_SERVER['REMOTE_ADDR'];
		}

    }
    
    public function trackOrder($observer)
    {
	    	
    	$order = $observer->getEvent()->getOrder();
    	$quote = $order->getQuote();

		$customer_id = $order->getCustomerId();
		$customer = Mage::getModel('customer/customer')->load($customer_id);
		$customer_email = $customer->getEmail();

    	$skus = array();
    	foreach ($order->getItemsCollection() as $item) {
    	
    		$product_id = $item->getProductId();

    		$product = Mage::getModel('catalog/product')
    	               ->setStoreId(Mage::app()->getStore()->getId())
    	               ->load($product_id);
    	        
    	    $skus[] = $product->getSku();
    		
    	}

		$order_date = $quote->getUpdatedAt();
		$order_date = str_replace(' ', 'T', $order_date);

		$revenue = $quote->getBaseGrandTotal();

		$person = array();
        $person = $this->getCustomerTrackInfo($customer);

        $additional = array();
        $additional['last_order'] = $order_date;
        $additional['skus'] = $skus;
		$this->trackPerson($person, $additional);
		$this->track_revenue($customer_email, $revenue);

		$this->track('purchase_complete', array('skus'  => $skus,
                                          'order_date'  => $order_date,
                                          'order_total'	=> $revenue,
                                          'distinct_id' => $this->getCustomerIdentity()));

    }

    public function trackCustomerSave($observer) {

    	$customer = $observer->getCustomer();

	    if ($customer->isObjectNew() && !$customer->getCustomerAlreadyProcessed()) {

	        $customer->setCustomerAlreadyProcessed(true);

	        $person = array();
	        $person = $this->getCustomerTrackInfo($customer);
    		$this->trackPerson($person);

	    }

    }

    public function getCustomerTrackInfo($customer) {

    	$person = array();
		$person['email'] = $customer->getEmail();
		$person['first_name'] = $customer->getFirstname();
		$person['last_name'] = $customer->getLastname();
		$person['created'] = $customer->getCreatedAt();
		$person['distinct_id'] = $customer->getEmail();

		return $person;
    }

    public function trackCustomerFromCheckout($observer) {

    	$order = $observer->getEvent()->getOrder();
    	$quoteId = $order->getQuoteId();
    	$quote = Mage::getModel('sales/quote')->load($quoteId);

    	$method = $quote->getCheckoutMethod(true);

    	if ($method == 'register') {

    		$customer_id = $order->getCustomerId();
    		$customer = Mage::getModel('customer/customer')->load($customer_id);
    		$customer_email = $customer->getEmail();
    		$this->alias($customer_email);

    		$person = array();
	        $person = $this->getCustomerTrackInfo($customer);
    		$this->trackPerson($person);

    	}
		
    }

    public function trackCoupon($observer) {

        $action = $observer->getEvent()->getControllerAction();
        $coupon_code = trim($action->getRequest()->getParam('coupon_code'));

        if (isset($coupon_code) && !empty($coupon_code)) {

            $this->track('coupon_used', array('code' => $coupon_code, 'distinct_id' => $this->getCustomerIdentity()));

        }

        return $this;

    }

    public function alias($identifier) {

    	$params['event'] = '$create_alias';
    	$params['properties']['distinct_id'] = $_SERVER['REMOTE_ADDR'];
    	$params['properties']['$initial_referrer'] = '$direct';
    	$params['properties']['$initial_referring_domain'] = '$direct';
        $params['properties']['alias'] = $identifier;
        $params['properties']['token'] = $this->token;
   
        $url = $this->host . 'track/?data=' . base64_encode(json_encode($params));
        exec("curl '" . $url . "' >/dev/null 2>&1 &"); 
    }
    
    public function track($event, $properties = array()) {
    
        $params = array(
            'event' => $event,
            'properties' => $properties
            );

        $params['properties']['token'] = $this->token;
        
        $url = $this->host . 'track/?data=' . base64_encode(json_encode($params));
        exec("curl '" . $url . "' >/dev/null 2>&1 &"); 
    }

    public function trackPerson($person, $additional = array()) {

    	$params = array();
    	$params['$set']['$email'] = $person['email'];
    	$params['$set']['$first_name'] = $person['first_name'];
    	$params['$set']['$last_name'] = $person['last_name'];
    	$params['$set']['$created'] = $person['created'];
    	$params['$token'] = $this->token;
    	$params['$ip'] = $_SERVER['REMOTE_ADDR'];
    	$params['$distinct_id'] = $person['distinct_id'];

    	if (!empty($additional)) {
			foreach ($additional as $key => $value) {
				$params['$set'][$key] = $value;
			}
		}

    	$url = $this->host . 'engage/?data=' . base64_encode(json_encode($params));
		exec("curl '" . $url . "' >/dev/null 2>&1 &");   

    }

    public function track_revenue($identifier, $revenue) {
    
		$params = array(
			'$append' => array(
				'$transactions' => array(
					'$time' => date('Y-m-d') . '-T' . date('H:i:s'),
					'$amount' => (float)$revenue
				),
			),
			'$token' => $this->token,
			'$ip' => $_SERVER['REMOTE_ADDR'],
			'$distinct_id' => $identifier
		);

		$url = 'http://api.mixpanel.com/engage/?data=' . base64_encode(json_encode($params));
		exec("curl '" . $url . "' >/dev/null 2>&1 &");

    }

}