<?php

//http://cgeers.wordpress.com/2010/04/08/ogone-payment-services/
//http://code.google.com/p/silverstripe-ecommerce/source/browse/modules/payment_ogone/trunk/code/OgonePayment.php?spec=svn733&r=733
//https://secure.ogone.com/ncol/Ogone_e-Com-ADV_EN.pdf
//https://secure.ogone.com/ncol/param_cookbook.asp
//http://www.inventis.be/blog/ogone-e-commerce-in-de-praktijk/
//http://www.vrwinery.com/modules/checkout/ogone/_svn/text-base/module.ogone.php.svn-base
//http://www.vrwinery.com/modules/checkout/ogone/_svn/text-base/module.ogone.php.svn-base


/*
 * 
VISA	 4111 1111 1111 1111
Visa 3-D Secure	 4000 0000 0000 0002
MasterCard	 5399 9999 9999 9999
American Express	 3741 1111 1111 111
Krijg je de foutmelding ‘unknown order/0/s‘ wilt dit zeggen dat je geen SHA-1 string hebt meegestuurd. Wat je dus wel degelijk moet doen…
Krijg je de melding ‘unknown order/1/s‘ dan wil dit zeggen dat de SHA-1 IN hash die Ogone gemaakt heeft niet overeenkomt met de hash die jij gemaakt hebt. Er dus ergens iets foutgelopen bij het maken van uw hash.
 */
use \PHPpayments\Common\Payment_Integration;
use \PHPpayments\Common\Payment_IntegrationInterface;


class Payment_Integration_Ogone extends  Payment_Integration implements  Payment_IntegrationInterface {
	
	
	public $url_submit = "https://secure.ogone.com/ncol/prod/orderstandard_utf8.asp";
	public $shortname = "ogone";
	
	public function enableTestMode() {
		parent::enableTestMode ();
		$this->url_submit = "https://secure.ogone.com/ncol/test/orderstandard_utf8.asp";
	}
	
	public function preparePayment() {
		
		$this->arr_payment ['PSPID'] = $this->arr_settings ['account'];
		$this->arr_payment ['ORDERID'] = $this->arr_order ['id'];
		$this->arr_payment ['AMOUNT'] = ( int ) ($this->arr_order ['total'] * 100);
		$this->arr_payment ['CURRENCY'] = $this->arr_order ['currency'];
		$this->arr_payment ['LANGUAGE'] = strtoupper ( $this->arr_settings ['language'] );
		$this->arr_payment ['CN'] = $this->arr_billing ['firstname'] . " " . $this->arr_billing ['lastname'];
		$this->arr_payment ['EMAIL'] = "";
		$this->arr_payment ['OWNERADDRESS'] = $this->arr_billing ['address1'];
		$this->arr_payment ['OWNERZIP'] = $this->arr_billing ['state'];
		$this->arr_payment ['OWNERTOWN'] = $this->arr_billing ['city'];
		$this->arr_payment ['OWNERCTY'] = $this->arr_billing ['country'];
		
		$this->arr_payment ['PARAMVAR'] = $this->arr_settings ['shopguid'];
		
		if (isset ( $this->arr_order ['guid'] )) {
			$this->arr_payment ['PARAMPLUS'] = "orderguid=" . $this->arr_order ['guid'];
		} elseif (isset ( $this->arr_order ['id'] )) {
			$this->arr_payment ['PARAMPLUS'] = "orderid=" . $this->arr_order ['id'];
		}
		
		$this->arr_payment ['ACCEPTURL'] = $this->url_return_success;
		$this->arr_payment ['DECLINEURL'] = $this->url_return_cancel;
		$this->arr_payment ['EXCEPTIONURL'] = $this->url_return_cancel;
		$this->arr_payment ['CANCELURL'] = $this->url_return_cancel;
		
		//sha
		$shaInputs = array_change_key_case ( $this->arr_payment, CASE_UPPER );
		ksort ( $shaInputs );
		foreach ( $shaInputs as $input => $value ) {
			if ($value && $value != '')
				$joinInputs [] = "$input=$value";
		}
		$sha = implode ( $this->arr_settings ['secret'], $joinInputs ) . $this->arr_settings ['secret'];
		$this->arr_payment ['SHASIGN'] = sha1 ( $sha );
		
		$this->url_integration = $this->url_submit . '?' . http_build_query ( $this->arr_payment );
	
	}
	
	public function validateIpn($arr_params) {
		try {
			
			parent::validateIpn ($arr_params);
			
			// verify the SHA Sign
			//$shaCheckFields = array ('AAVADDRESS', 'AAVCHECK','AAVMAIL','AAVNAME','AAVPHONE', 'AAVZIP', 'ACCEPTANCE', 'ALIAS', 'AMOUNT', 'BIC', 'BIN','BRAND', 'CARDNO', 'CCCTY', 'CN', 'COMPLUS','CREATION_STATUS', 'CREDITDEBIT','CURRENCY', 'CVCCHECK', 'DCC_COMMPERCENTAGE', 'DCC_CONVAMOUNT', 'DCC_CONVCCY', 'DCC_EXCHRATE', 'DCC_EXCHRATESOURCE', 'DCC_EXCHRATETS', 'DCC_INDICATOR', 'DCC_MARGINPERCENTAGE', 'DCC_VALIDHOURS', 'DIGESTCARDNO', 'ECI', 'ED', 'ENCCARDNO','FXAMOUNT','FXCURRENCY','IBAN',  'IP', 'IPCTY', 'MOBILEMODE', 'NBREMAILUSAGE', 'NBRIPUSAGE', 'NBRIPUSAGE_ALLTX', 'NBRUSAGE', 'NCERROR', 'NCERRORCARDNO','NCERRORCN','NCERRORCVC','NCERRORED','ORDERID', 'PAYID', 'PM', 'SCO_CATEGORY', 'SCORING', 'STATUS','SUBBRAND', 'SUBSCRIPTION_ID', 'TRXDATE', 'VC' );
			
			$arr_check = array_change_key_case ( $arr_params, CASE_UPPER );
			
			
			$signature =  $this->arr_settings ['secret2'];

			ksort($arr_check);
			unset($arr_check ['SHASIGN']);
			unset($arr_check ['ORDERGUID']);
			
			$stringToHash = "";
			foreach ( $arr_check as $key => $value ) {
			    if ( $value == "" ) {
			       continue;
			    }
			    $stringToHash .= $key . '=' . $value . $signature;
			}
					
			
			
			$sha_calc = strtoupper ( sha1 ( $stringToHash ) );
			
			$sha_post = $arr_params ['SHASIGN'];
			$this->payment_result->log .= print_r ( $arr_params, true );
			$this->payment_result->log .= "SHASIGN:$sha_post|$sha_calc";
			if ($sha_post == $sha_calc) {
				//valid
				$this->payment_result->transaction = $arr_params ['PAYID'];
				if ($arr_params ['ACCEPTANCE'] != "" && $arr_params ['STATUS'] == 9) {
					$this->payment_result->confirmed = 1;
				} else {
					$this->payment_result->confirmed = 0;
				}
			
			} else {
				//invalid
				$this->payment_result->confirmed = 0;
			}
		
		} catch ( Exception $e ) {
			$this->payment_result->log .= "CATCH" . print_r ( $e, true );
			$this->payment_result->error = 001;
			$this->payment_result->confirmed = 0;
		}
		
		return $this->payment_result;
	}

}
