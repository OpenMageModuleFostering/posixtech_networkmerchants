<?php
/**
 * Posixtech Ltd.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@posixtech.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * You can edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future.
 *
 * =================================================================
 *                 MAGENTO EDITION USAGE NOTICE
 * =================================================================
 * This package designed for Magento COMMUNITY edition version 1.5.0.0 to all upper version.
 * Posixtech Ltd. does not guarantee correct work of this extension
 * on any other Magento edition except Magento COMMUNITY edition.
 * Posixtech Ltd. does not provide extension support in case of
 * incorrect edition usage.
 * =================================================================
 *
 * @category   Posixtech
 * @package    NetworkMerchants
 * @copyright  Copyright (c) 2013 Posixtech Ltd. (http://www.posixtech.com)
 * @license    http://www.posixtech.com/POSIXTECH_LTD_LICENSE.txt
 */

class Posixtech_NetworkMerchants_Model_PaymentMethod extends Mage_Payment_Model_Method_Cc
{
	protected $_code = 'networkmerchants';
	protected $_isGateway = true;
	protected $_canAuthorize = true;
	protected $_canCapture = true;
	protected $_canRefund = true;
	protected $_canVoid = true;
	protected $_canUseCheckout = true;
	protected $_canSaveCC = false;
    protected $_canUseInternal = true;
    protected $_canFetchTransactionInfo = true;

    protected $_formBlockType = 'networkmerchants/direct_form_cc';
    protected $_infoBlockType = 'networkmerchants/direct_info_cc';
    
	private $_gatewayURL = 'https://secure.nmi.com/api/transact.php';
    
    const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';
    const REQUEST_TYPE_AUTH_ONLY    = 'AUTH_ONLY';
    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE_ONLY';
    
    const RESPONSE_CODE_APPROVED = 1;
    const RESPONSE_CODE_DECLINED = 2;
    const RESPONSE_CODE_ERROR    = 3;
    const RESPONSE_CODE_HELD     = 4;
    
    const APPROVED     = 1;
    const DECLINED     = 2;
    const ERROR     = 3;

    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setCcType($data->getCcType())
            ->setCcOwner($data->getCcOwner())
            ->setCcLast4(substr($data->getCcNumber(), -4))
            ->setCcNumber($data->getCcNumber())
            ->setCcCid($data->getCcCid())
            ->setCcExpMonth($data->getCcExpMonth())
            ->setCcExpYear($data->getCcExpYear())
            ->setCcSsIssue($data->getCcSsIssue())
            ->setCcSsStartMonth($data->getCcSsStartMonth())
            ->setCcSsStartYear($data->getCcSsStartYear())
            ;
        return $this;
    }
    
	/**
	 * Send authorize request to gateway
	 * 
	 */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('networkmerchants')->__('Invalid amount for authorization.'));
        }
        
        $payment->setAmount($amount);
        $payment->setAnetTransType(self::REQUEST_TYPE_AUTH_ONLY);
        
        $request = $this->_buildRequest($payment);
        $r = $this->doSale($request);
        
        if (isset($r['response']) && ($r['response'] == 1)) {
            $payment->setTransactionId($r['transactionid'])
                ->setCcApproval($r['authcode'])
                ->setCcTransId($r['transactionid'])
                ->setIsTransactionClosed(0)
                ->setParentTransactionId(null)
                ->setCcAvsStatus($r['avsresponse'])
                ->setCcCidStatus($r['cvvresponse']);
            return $this;
        } else {
            Mage::throwException('Transaction Declined: ' . $r['responsetext']);
        } 
        return $this;
    }
    
    protected function _buildRequest(Varien_Object $payment)
    {
        $order = $payment->getOrder();
        
        $request = $this->_getRequest();
        
        $testMode = $this->getConfigData('test_mode');
        
        if($testMode) {
            $request->setLoginUserName('demo')
		            ->setLoginPassword('password');
        } else {
            $username = $this->getConfigData('username');
            $password = $this->getConfigData('password');
            $request->setLoginUserName($username)
                ->setLoginPassword($password);
        }
        
        $billing = $order->getBillingAddress();
        
        if (!empty($billing)) {
            $request->setBillingFirstname(strval($billing->getFirstname()));
            $request->setBillingLastname(strval($billing->getLastname()));
            $request->setBillingCompany(strval($billing->getCompany()));
            $request->setBillingAddress1(strval($billing->getStreet(1)));
            $request->setBillingAddress2(strval($billing->getStreet(2)));
            $request->setBillingCity(strval($billing->getCity()));
            $request->setBillingState(strval($billing->getRegion()));
            $request->setBillingZip(strval($billing->getPostcode()));
            $request->setBillingCountry(strval($billing->getCountry()));
            $request->setBillingPhone(strval($billing->getTelephone()));
            $request->setBillingFax(strval($billing->getFax()));
            $request->setBillingEmail(strval($order->getCustomerEmail()));
            $request->setBillingWebsite("");
        }
        
        $shipping = $order->getShippingAddress();
        if (empty($shipping)) {
            $shipping = $billing;
        }
        
        if (!empty($shipping)) {
            $request->setShippingFirstname(strval($shipping->getFirstname()));
            $request->setShippingLastname(strval($shipping->getLastname()));
            $request->setShippingCompany(strval($shipping->getCompany()));
            $request->setShippingAddress1(strval($shipping->getStreet(1)));
            $request->setShippingAddress2(strval($shipping->getStreet(2)));
            $request->setShippingCity(strval($shipping->getCity()));
            $request->setShippingState(strval($shipping->getRegion()));
            $request->setShippingZip(strval($shipping->getPostcode()));
            $request->setShippingCountry(strval($shipping->getCountry()));
            $request->setShippingEmail(strval($order->getCustomerEmail()));
        }
        
        $request->setOrderid($order->getIncrementId());
        $request->setOrderdescription('');
        $request->setTax(sprintf('%.2F', $order->getBaseTaxAmount()));
        $request->setShipping(sprintf('%.2F', $order->getBaseShippingAmount()));
        $request->setPonumber(strval($payment->getPoNumber()));
        $request->setIpaddress(strval($order->getRemoteIp()));

        $ccNumber = '';
        $expDate = '';
        $ccCid = '';
        if($payment->getCcNumber()){
            $ccNumber = $payment->getCcNumber();
            $yr = substr($payment->getCcExpYear(), -2);
            $expDate = sprintf('%02d%02d', $payment->getCcExpMonth(), $yr);
            $ccCid = $payment->getCcCid();
        } else {
            Mage::throwException('Wrong Credit Card Number');
        }

        $request->setCreditCardNumber($ccNumber);
        $request->setCcExp($expDate);
        $request->setCvv($ccCid);
        
        if($payment->getAmount()){
            $request->setAmount($payment->getAmount());
        }
        
        return $request;
    }
    
    protected function _getRequest()
    {
        return Mage::getModel('networkmerchants/direct_request');
    }
    
    public function doSale($request) {
        $query  = "";

        // Login Information
        $query .= "username=" . urlencode($request->getLoginUserName()) . "&";
        $query .= "password=" . urlencode($request->getLoginPassword()) . "&";
        
        // Sales Information
        $query .= "ccnumber=" . urlencode($request->getCreditCardNumber()) . "&";
        $query .= "ccexp=" . urlencode($request->getCcExp()) . "&";
        $query .= "amount=" . urlencode(number_format($request->getAmount(),2,".","")) . "&";
        $query .= "cvv=" . urlencode($request->getCvv()) . "&";
        
        // Order Information
        $query .= "ipaddress=" . urlencode($request->getIpaddress()) . "&";
        $query .= "orderid=" . urlencode($request->getOrderid()) . "&";
        $query .= "orderdescription=" . urlencode($request->getOrderdescription()) . "&";
        $query .= "tax=" . urlencode(number_format($request->getTax(),2,".","")) . "&";
        $query .= "shipping=" . urlencode(number_format($request->getShipping(),2,".","")) . "&";
        $query .= "ponumber=" . urlencode($request->getPonumber()) . "&";
        
        // Billing Information
        $query .= "firstname=" . urlencode($request->getBillingFirstname()) . "&";
        $query .= "lastname=" . urlencode($request->getBillingLastname()) . "&";
        $query .= "company=" . urlencode($request->getBillingCompany()) . "&";
        $query .= "address1=" . urlencode($request->getBillingAddress1()) . "&";
        $query .= "address2=" . urlencode($request->getBillingAddress2()) . "&";
        $query .= "city=" . urlencode($request->getBillingCity()) . "&";
        $query .= "state=" . urlencode($request->getBillingState()) . "&";
        $query .= "zip=" . urlencode($request->getBillingZip()) . "&";
        $query .= "country=" . urlencode($request->getBillingCountry()) . "&";
        $query .= "phone=" . urlencode($request->getBillingPhone()) . "&";
        $query .= "fax=" . urlencode($request->getBillingFax()) . "&";
        $query .= "email=" . urlencode($request->getBillingEmail()) . "&";
        $query .= "website=" . urlencode($request->getBillingWebsite()) . "&";
        
        // Shipping Information
        $query .= "shipping_firstname=" . urlencode($request->getShippingFirstname()) . "&";
        $query .= "shipping_lastname=" . urlencode($request->getShippingLastname()) . "&";
        $query .= "shipping_company=" . urlencode($request->getShippingCompany()) . "&";
        $query .= "shipping_address1=" . urlencode($request->getShippingAddress1()) . "&";
        $query .= "shipping_address2=" . urlencode($request->getShippingAddress2()) . "&";
        $query .= "shipping_city=" . urlencode($request->getShippingCity()) . "&";
        $query .= "shipping_state=" . urlencode($request->getShippingState()) . "&";
        $query .= "shipping_zip=" . urlencode($request->getShippingZip()) . "&";
        $query .= "shipping_country=" . urlencode($request->getShippingCountry()) . "&";
        $query .= "shipping_email=" . urlencode($request->getShippingEmail()) . "&";
        $query .= "type=auth";
        
        return $this->_doPost($query);
    }
    
    /**
     * Send capture request to gateway
     * 
     */
    public function capture(Varien_Object $payment, $amount) {

        if ($amount <= 0) {
            Mage::throwException(Mage::helper('networkmerchants')->__('Invalid amount for capture.'));
        }

        $transactionid = $payment->getTransactionId();

        if(!$transactionid) {
            $this->_preauthorizeCapture($payment,$amount);
            $transactionid = $payment->getTransactionId();
        }

        $testMode = $this->getConfigData('test_mode');
        $username = null;
        $password = null;
        if($testMode) {
            $username = 'demo';
            $password = 'password';
        } else {
            $username = $this->getConfigData('username');
            $password = $this->getConfigData('password');
        }
        
        $query  = "";
        // Login Information
        $query .= "username=" . urlencode($username) . "&";
        $query .= "password=" . urlencode($password) . "&";
        
        // Transaction Information
        $query .= "transactionid=" . urlencode($transactionid) . "&";
        if ($amount>0) {
            $query .= "amount=" . urlencode(number_format($amount,2,".","")) . "&";
        }
        $query .= "type=capture";
        
        $result = $this->_doPost($query);

        if (isset($result['response']) && ($result['response'] == 1)) {
            $payment->setStatus(self::STATUS_APPROVED);
            $payment->setLastTransId($result['transactionid']);
            if (!$payment->getParentTransactionId() || $result['transactionid'] != $payment->getParentTransactionId()) {
                $payment->setTransactionId($result['transactionid']);
            }
            return $this;
        } else {
            Mage::throwException('Transaction Declined: ' . $result['transactionid']);
        }
    }
    
    /**
     * Send capture request to gateway for capture authorized transactions
     *
     * @param Mage_Payment_Model_Info $payment
     * @param decimal $amount
     */
    protected function _preauthorizeCapture($payment, $requestedAmount)
    {
        $payment->setAmount($requestedAmount);
        $payment->setAnetTransType(self::REQUEST_TYPE_AUTH_CAPTURE);
        
        $request = $this->_buildRequest($payment);
        
        $r = $this->doSale($request);
        
        if (isset($r['response']) && ($r['response'] == 1)) {
            $payment->setTransactionId($r['transactionid'])
                ->setCcApproval($r['authcode'])
                ->setCcTransId($r['transactionid'])
                ->setIsTransactionClosed(0)
                ->setParentTransactionId(null)
                ->setCcAvsStatus($r['avsresponse'])
                ->setCcCidStatus($r['cvvresponse']);
            return $this;
        } else {
            Mage::throwException('Transaction Declined: ' . $r['responsetext']);
        } 
        return $this;
    }
    
    public function _doPost($query) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->_gatewayURL);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_POST, 1);

        if (!($data = curl_exec($ch))) {
            Mage::throwException('Transaction Declined: error transaction');
            return ERROR;
        }
        curl_close($ch);
        unset($ch);
        $responses = array();
        $data = explode("&",$data);
        for($i=0;$i<count($data);$i++) {
            $rdata = explode("=",$data[$i]);
            $responses[$rdata[0]] = $rdata[1];
        }
        return $responses;
    }
    
    /**
     * Refund the amount
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     * @return GatewayProcessingServices_ThreeStep_Model_PaymentMethod
     * @throws Mage_Core_Exception
     */
    public function refund(Varien_Object $payment, $amount) {
        if ($payment->getRefundTransactionId() && $amount > 0) {
            $order = $payment->getOrder();

            $testMode = $this->getConfigData('test_mode');
            $username = '';
            $password = '';
            if($testMode) {
                $username = 'demo';
                $password = 'password';
            } else {
                $username = $this->getConfigData('username');
                $password = $this->getConfigData('password');
            }
            
            $query  = "";
            // Login Information
            $query .= "username=" . urlencode($username) . "&";
            $query .= "password=" . urlencode($password) . "&";
            // Transaction Information
            $query .= "transactionid=" . urlencode($payment->getParentTransactionId()) . "&";
            if ($amount>0) {
                $query .= "amount=" . urlencode(number_format($amount,2,".","")) . "&";
            }
            $query .= "type=refund";
            $result = $this->_doPost($query);
            if (isset($result['response']) && ($result['response'] == 1)) {
                $payment->setStatus(self::STATUS_SUCCESS );
                 return $this;
            } else {
                $payment->setStatus(self::STATUS_ERROR);
                Mage::throwException('Refund Failed: Invalid transaction ID');
            }
        }
        $payment->setStatus(self::STATUS_ERROR);
        Mage::throwException('Refund Failed: Invalid transaction ID');
    }
    
    /**
     * Void the payment through gateway
     *
     * @param Varien_Object $payment
     * @return GatewayProcessingServices_ThreeStep_Model_PaymentMethod
     * @throws Mage_Core_Exception
     */
    public function void(Varien_Object $payment) {
        
        if ($payment->getParentTransactionId()) {
            $order = $payment->getOrder();

            $testMode = $this->getConfigData('test_mode');
            $username = '';
            $password = '';
            if($testMode) {
                $username = 'demo';
                $password = 'password';
            } else {
                $username = $this->getConfigData('username');
                $password = $this->getConfigData('password');
            }
            
            $query  = "";
            // Login Information
            $query .= "username=" . urlencode($username) . "&";
            $query .= "password=" . urlencode($password) . "&";
            // Transaction Information
            $query .= "transactionid=" . urlencode($payment->getParentTransactionId()) . "&";
            $query .= "type=void";
            $result = $this->_doPost($query);
            if (isset($result['response']) && ($result['response'] == 1)) {
                $payment->setStatus(self::STATUS_SUCCESS );
                 return $this;
            } else {
                $payment->setStatus(self::STATUS_ERROR);
                Mage::throwException('Void Failed: Invalid transaction ID.');
            }
        }
        $payment->setStatus(self::STATUS_ERROR);
        Mage::throwException('Void Failed: Invalid transaction ID.');
    }
    
    public function cancel(Varien_Object $payment)
    {
        return $this->void($payment);
    }
}

?>
