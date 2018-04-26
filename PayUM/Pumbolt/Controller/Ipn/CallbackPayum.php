<?php
/** 
 * @copyright  Citruspay
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace PayUM\Pumbolt\Controller\Ipn;

use Magento\Framework\App\Config\ScopeConfigInterface;

use Magento\Framework\App\Action\Action as AppAction;

class CallbackPayum extends AppAction
{
    /**
    * @var \Citrus\Icp\Model\PaymentMethod
    */
    protected $_paymentMethod;

    /**
    * @var \Magento\Sales\Model\Order
    */
    protected $_order;

    /**
    * @var \Magento\Sales\Model\OrderFactory
    */
    protected $_orderFactory;

    /**
    * @var Magento\Sales\Model\Order\Email\Sender\OrderSender
    */
    protected $_orderSender;

    /**
    * @var \Psr\Log\LoggerInterface
    */
    protected $_logger;
	
	protected $request;

    /**
    * @param \Magento\Framework\App\Action\Context $context
    * @param \Magento\Sales\Model\OrderFactory $orderFactory
    * @param \Citrus\Icp\Model\PaymentMethod $paymentMethod
    * @param Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    * @param  \Psr\Log\LoggerInterface $logger
    */
    public function __construct(
    \Magento\Framework\App\Action\Context $context,
	\Magento\Framework\App\Request\Http $request,
    \Magento\Sales\Model\OrderFactory $orderFactory,
    \PayUM\Pumbolt\Model\PaymentMethod $paymentMethod,
    \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,	
    \Psr\Log\LoggerInterface $logger
    ) {
        $this->_paymentMethod = $paymentMethod;
        $this->_orderFactory = $orderFactory;
        $this->_client = $this->_paymentMethod->getClient();
        $this->_orderSender = $orderSender;		
        $this->_logger = $logger;	
		$this->request = $request;
        parent::__construct($context);
    }

    /**
    * Handle POST request to PayUMoney callback endpoint.
    */
    public function execute()
    {
        try {
            // Cryptographically verify authenticity of callback
            if($this->request->getPost())
			{				
				$this->_success();
				$this->paymentAction();
			}
			else
			{
	            $this->_logger->addError("PayUMoney: no post back data received in callback");
				return $this->_failure();
			}
        } catch (Exception $e) {
            $this->_logger->addError("PayUMoney: error processing callback");
            $this->_logger->addError($e->getMessage());
            return $this->_failure();
        }
		
		$this->_logger->addInfo("PayUMoney Transaction END from PayUMoney");
    }
	
	protected function paymentAction()
	{
		$pumkey = $this->_paymentMethod->getConfigData('pumkey');	
		$pumsalt = $this->_paymentMethod->getConfigData('pumsalt');	

		if ($this->getRequest ()->isPost ()) {
			
			$postdata = $this->getRequest ()->getPost ();			
			
			if (isset($postdata ['key']) && ($postdata['key'] == $pumkey)) {
				$ordid = $postdata['txnid'];
    	    	$this->_loadOrder($ordid);

				$amount      		= 	$postdata['amount'];
				$productInfo  		= 	$postdata['productinfo'];
				$firstname    		= 	$postdata['firstname'];
				$email        		=	$postdata['email'];
				$udf5				= 	$postdata['udf5'];
				$keyString 	  		=  	$pumkey.'|'.$ordid.'|'.$amount.'|'.$productInfo.'|'.$firstname.'|'.$email.'|||||'.$udf5.'|||||';
				$keyArray 	  		= 	explode("|",$keyString);
				$reverseKeyArray 	= 	array_reverse($keyArray);
				$reverseKeyString	=	implode("|",$reverseKeyArray);
				
				$message = '';
				$message .= 'orderId / Transaction ID: ' . $ordid . "\n";
				//$message .= 'Transaction Id: ' . $postdata['mihpayid'] . "\n";
				
				
				if (isset($postdata['status']) && $postdata['status'] == 'success') {
				 	$saltString     = $pumsalt.'|'.$postdata['status'].'|'.$reverseKeyString;
					$sentHashString = strtolower(hash('sha512', $saltString));
				 	$responseHashString=$postdata['hash'];
				
					
					/*foreach($postdata as $k => $val){
						$message .= $k.': ' . $val . "\n";
					}*/
					$message .= 'payid : '.$postdata['mihpayid'];
					
					if($sentHashString==$responseHashString){
						// success	
						$this->_registerPaymentCapture ($ordid, $amount, $message);
						//$this->_logger->addInfo("Payum Response Order success..".$txMsg);
				
						$redirectUrl = $this->_paymentMethod->getSuccessUrl();
						//AA Where 
						$this->_redirect($redirectUrl);
					}
					else {
						//tampered
						//$this->_order->hold()->save();
						$this->_createPayUMComment("PayUMoney Response signature does not match. You might have received tampered data", true);
						$this->_order->cancel()->save();

						$this->_logger->addError("PayUMoney Response signature did not match ");

						//AA display error to customer = where ???
						$this->messageManager->addError("<strong>Error:</strong> PayUMoney Response signature does not match. You might have received tampered data");
						$this->_redirect('checkout/onepage/failure');
					}
				} else {
		    		$historymessage = $message;//.'<br/>View Citrus Payment using the following URL: '.$enquiryurl;
			
					$this->_createPayUMComment($historymessage);
					$this->_order->cancel()->save();				

					//$this->_logger->addInfo("Payum Response Order cancelled ..");
			
					$this->messageManager->addError("<strong>Error:</strong> $message <br/>");
					//AA where 
					$redirectUrl = $this->_paymentMethod->getCancelUrl();
					$this->_redirect($redirectUrl);			
				} 
			}
		}
	}
	

	//AA - To review - required 
    protected function _registerPaymentCapture($transactionId, $amount, $message)
    {
        $payment = $this->_order->getPayment();
		
		
        $payment->setTransactionId($transactionId)       
        ->setPreparedMessage($this->_createPayUMComment($message))
        ->setShouldCloseParentTransaction(true)
        ->setIsTransactionClosed(0)
		->setAdditionalInformation(['pumbolt','payumoney'])		
        ->registerCaptureNotification(
		//AA
            $amount,
            true 
        );

        $this->_order->save();

        $invoice = $payment->getCreatedInvoice();
        if ($invoice && !$this->_order->getEmailSent()) {
            $this->_orderSender->send($this->_order);
            $this->_order->addStatusHistoryComment(
                __('You notified customer about invoice #%1.', $invoice->getIncrementId())
            )->setIsCustomerNotified(
                true
            )->save();
        }
    }

	//AA Done
    protected function _loadOrder($order_id)
    {
        $this->_order = $this->_orderFactory->create()->loadByIncrementId($order_id);

        if (!$this->_order && $this->_order->getId()) {
            throw new Exception('Could not find Magento order with id $order_id');
        }
    }

	//AA Done
    protected function _success()
    {
        $this->getResponse()
             ->setStatusHeader(200);
    }

	//AA Done
    protected function _failure()
    {
        $this->getResponse()
             ->setStatusHeader(400);
    }

    /**
    * Returns the generated comment or order status history object.
    *
    * @return string|\Magento\Sales\Model\Order\Status\History
    */
	//AA Done
    protected function _createPayUMComment($message = '')
    {       
        if ($message != '')
        {
            $message = $this->_order->addStatusHistoryComment($message);
            $message->setIsCustomerNotified(null);
        }
		
        return $message;
    }
	
}
