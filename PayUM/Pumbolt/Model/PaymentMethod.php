<?php
/** 
 *
 * @copyright  PayUMoney
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace PayUM\Pumbolt\Model;

use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'pumbolt';
    protected $_isInitializeNeeded = true;

    /**
    * @var \Magento\Framework\Exception\LocalizedExceptionFactory
    */
    protected $_exception;

    /**
    * @var \Magento\Sales\Api\TransactionRepositoryInterface
    */
    protected $_transactionRepository;

    /**
    * @var Transaction\BuilderInterface
    */
    protected $_transactionBuilder;

    /**
    * @var \Magento\Framework\UrlInterface
    */
    protected $_urlBuilder;

    /**
    * @var \Magento\Sales\Model\OrderFactory
    */
    protected $_orderFactory;
	protected $_countryHelper;
    /**
    * @var \Magento\Store\Model\StoreManagerInterface
    */
    protected $_storeManager;
	
	protected $adnlinfo;
	protected $title;

    /**
    * @param \Magento\Framework\UrlInterface $urlBuilder
    * @param \Magento\Framework\Exception\LocalizedExceptionFactory $exception
    * @param \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
    * @param Transaction\BuilderInterface $transactionBuilder
    * @param \Magento\Sales\Model\OrderFactory $orderFactory
    * @param \Magento\Store\Model\StoreManagerInterface $storeManager
    * @param \Magento\Framework\Model\Context $context
    * @param \Magento\Framework\Registry $registry
    * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
    * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
    * @param \Magento\Payment\Helper\Data $paymentData
    * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    * @param \Magento\Payment\Model\Method\Logger $logger
    * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
    * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
    * @param array $data
    */
    public function __construct(
      \Magento\Framework\UrlInterface $urlBuilder,
      \Magento\Framework\Exception\LocalizedExceptionFactory $exception,
      \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
      \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
      \Magento\Sales\Model\OrderFactory $orderFactory,
      \Magento\Store\Model\StoreManagerInterface $storeManager,
      \Magento\Framework\Model\Context $context,
      \Magento\Framework\Registry $registry,
      \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
      \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
      \Magento\Payment\Helper\Data $paymentData,
      \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
      \Magento\Payment\Model\Method\Logger $logger,
      \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
      \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
      array $data = []
    ) {
      $this->_urlBuilder = $urlBuilder;
      $this->_exception = $exception;
      $this->_transactionRepository = $transactionRepository;
      $this->_transactionBuilder = $transactionBuilder;
      $this->_orderFactory = $orderFactory;
      $this->_storeManager = $storeManager;
	  $this->_countryHelper = \Magento\Framework\App\ObjectManager::getInstance()->get('\Magento\Directory\Model\Country');
      parent::__construct(
          $context,
          $registry,
          $extensionFactory,
          $customAttributeFactory,
          $paymentData,
          $scopeConfig,
          $logger,
          $resource,
          $resourceCollection,
          $data
      );
    }

    /**
     * Instantiate state and set it to state object.
     *
     * @param string                        $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);		
		
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

	
	//AA Done
	public function _generateHmacKey($data, $apiKey=null){
		//$hmackey = Zend_Crypt_Hmac::compute($apiKey, "sha1", $data);
		$hmackey = hash_hmac('sha1',$data,$apiKey);
		return $hmackey;
	}

	
	public function getPostHTML($order, $storeId = null)
    {
			$environment =  $this->getConfigData('environment');
			$pumkey = 		$this->getConfigData('pumkey');	
			$pumsalt =		$this->getConfigData('pumsalt');	
			
			$txnid = $order->getIncrementId();
    	    $amount = $order->getGrandTotal();
        	$amount = number_format((float)$amount, 2, '.', '');
        	
			$action = "<script id='bolt' src='https://checkout-static.citruspay.com/bolt/run/bolt.min.js' bolt-color='e34524'  bolt-logo='http://boltiswatching.com/wp-content/uploads/2015/09/Bolt-Logo-e14421724859591.png'></script>";
			
			if($environment == 'sandbox')
				$action = "<script id='bolt' src='https://sboxcheckout-static.citruspay.com/bolt/run/bolt.min.js' bolt-color='e34524' bolt-logo='http://boltiswatching.com/wp-content/uploads/2015/09/Bolt-Logo-e14421724859591.png'></script>";
			
			$currency = $order->getOrderCurrencyCode();
        	$billingAddress = $order->getBillingAddress();
			$productInfo  = "Product Information";	        
			
			$firstname = $billingAddress->getData('firstname');
			$lastname = $billingAddress->getData('lastname');
			$zipcode = $billingAddress->getData('postcode');
			$email = $billingAddress->getData('email');
			$phone = $billingAddress->getData('telephone');
			$address = $billingAddress->getStreet();
        	$state = $billingAddress->getData('region');
        	$city = $billingAddress->getData('city');
        	$country = $billingAddress->getData('country_id');
			$countryObj = $this->_countryHelper->loadByCode($country);
			$country = $countryObj->getName();
			
			$surl = self::getPayUMReturnUrl();			
			
			$udf5 = 'Magento_v_2.2_BOLT';
			
			$hash=hash('sha512', $pumkey.'|'.$txnid.'|'.$amount.'|'.$productInfo.'|'.$firstname.'|'.$email.'|||||'.$udf5.'||||||'.$pumsalt); 
			
			$html = $action."			
				<script>
					var meta = document.createElement('meta');
					meta.name = 'viewport';
					meta.content = 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no';
					document.getElementsByTagName('head')[0].appendChild(meta);
				
						function launchBOLT()
						{
							if(typeof bolt == 'undefined'){
								alert('BOLT not loaded yet...');
								return false;
							}

						bolt.launch({
						key: '".$pumkey."',
						txnid: '".$txnid."', 
						hash: '".$hash."',
						amount: '".$amount."',
						firstname: '".$firstname."',
						email: '".$email."',
						phone: '".$phone."',
						productinfo: '".$productInfo."',
						udf5: '".$udf5."',
						surl : '".$surl."',
						furl: '".$surl."'
						},{ responseHandler: function(BOLT){
								console.log( BOLT.response.txnStatus );		
								if(BOLT.response.txnStatus != 'CANCEL')
								{
								var fr = '<form action=\"". $surl."\" method=\"post\">' +
  								'<input type=\"hidden\" name=\"key\" value=\"'+BOLT.response.key+'\" />' +
								'<input type=\"hidden\" name=\"txnid\" value=\"'+BOLT.response.txnid+'\" />' +
								'<input type=\"hidden\" name=\"amount\" value=\"'+BOLT.response.amount+'\" />' +
								'<input type=\"hidden\" name=\"productinfo\" value=\"'+BOLT.response.productinfo+'\" />' +
								'<input type=\"hidden\" name=\"firstname\" value=\"'+BOLT.response.firstname+'\" />' +
								'<input type=\"hidden\" name=\"email\" value=\"'+BOLT.response.email+'\" />' +
								'<input type=\"hidden\" name=\"udf5\" value=\"'+BOLT.response.udf5+'\" />' +
								'<input type=\"hidden\" name=\"mihpayid\" value=\"'+BOLT.response.mihpayid+'\" />' +
								'<input type=\"hidden\" name=\"status\" value=\"'+BOLT.response.status+'\" />' +
								'<input type=\"hidden\" name=\"hash\" value=\"'+BOLT.response.hash+'\" />' +
  								'</form>';
								var form = jQuery(fr);
								jQuery('body').append(form);								
								form.submit();
								}
							},
							catchException: function(BOLT){
								alert( BOLT.message );
							}
						});
						}
						setTimeout(launchBOLT, 2500);

					</script>";
					
			return $html;		
    }

    public function getOrderPlaceRedirectUrl($storeId = null)
    {
        return $this->_getUrl('pumbolt/checkout/start', $storeId);
    }

	protected function addHiddenField($arr)
	{
		$nm = $arr['name'];
		$vl = $arr['value'];	
		$input = "<input name='".$nm."' type='hidden' value='".$vl."' />";	
		
		return $input;
	}
	
    /**
     * Get return URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
	 //AA may not be required
    public function getSuccessUrl($storeId = null)
    {
        return $this->_getUrl('checkout/onepage/success', $storeId);
    }

	/**
     * Get return (IPN) URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
	 //AA Done
    
	 public function getPayUMReturnUrl($storeId = null)
    {
        return $this->_getUrl('pumbolt/ipn/callbackpayum', $storeId, false);
    }
	/**
     * Get cancel URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
	 //AA Not required
    public function getCancelUrl($storeId = null)
    {
        return $this->_getUrl('checkout/onepage/failure', $storeId);
    }

	/**
     * Build URL for store.
     *
     * @param string    $path
     * @param int       $storeId
     * @param bool|null $secure
     *
     * @return string
     */
	 //AA Done
    protected function _getUrl($path, $storeId, $secure = null)
    {
        $store = $this->_storeManager->getStore($storeId);

        return $this->_urlBuilder->getUrl(
            $path,
            ['_store' => $store, '_secure' => $secure === null ? $store->isCurrentlySecure() : $secure]
        );
    }
}
