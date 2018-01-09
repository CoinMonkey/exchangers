<?php
namespace coinmonkey\exchangers\tools;

class Changer
{    
    private $_Auth;
    
    public function __construct(ChangerAuth $auth = null) {
        $this->_Auth = $auth;
    }
    
    private function _parseResponse($apiRes) {      
        $jsonRes = json_decode($apiRes);
		
        if ($jsonRes === null)
            throw new \Exception('Could not load API response.');
        if (isset($jsonRes->error) && $jsonRes->error !== false)
            throw new \Exception($jsonRes->error);
		
        return $jsonRes;
    }

    public function getRate($send, $receive) {
        $apiRes = $this->_getResponse('/api/v2/rates/'.$send.'/'.$receive);
        $jsonRes = $this->_parseResponse($apiRes);
		
		return $jsonRes;
    }

    public function getLimits($send, $receive) {
        $apiRes = $this->_getResponse('/api/v2/limits/'.$send.'/'.$receive);
        $jsonRes = $this->_parseResponse($apiRes);

        return $jsonRes;
    }

    public function makeExchange($orderData) {
        $apiRes = $this->_getResponse('/api/v2/exchange', $orderData);
        $jsonRes = $this->_parseResponse($apiRes);

        return $jsonRes;
    }


    public function confirmExchange($exchangeID, $batch) {
        $apiRes = $this->_getResponse('/api/v2/exchange/'.$exchangeID, $batch);
        $jsonRes = $this->_parseResponse($apiRes);

        return $jsonRes;
    }

    public function checkExchange($exchangeID) {
        $apiRes = $this->_getResponse('/api/v2/exchange/'.$exchangeID);
        $jsonRes = $this->_parseResponse($apiRes);

        return $jsonRes;
    }
	
    private function _createHeaders($apiMethod, $apiData) {
		$sign = hash_hmac('sha256', $apiMethod.':'.http_build_query($apiData).':'.$this->_Auth->getApiTimestamp(), $this->_Auth->getApiSecure());
        return array(
			'Api-Key: ' . $this->_Auth->getApiKey(),
			'Api-Sign: ' . $sign,
			'Api-Timestamp: ' . $this->_Auth->getApiTimestamp()
		);
    }
    
    private function _getResponse($apiMethod, $apiData = array()) {
        if (!function_exists('curl_init')) {
            die("Please install cURL library. Visit http://php.net/manual/en/book.curl.php for more informations.");
            return false;
        }
        
        $apiURL = 'https://www.changer.com' . $apiMethod;

        $ch = curl_init();
        if(!is_null($this->_Auth))
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->_createHeaders($apiMethod, $apiData));

        curl_setopt($ch, CURLOPT_URL, $apiURL);
		if(count($apiData)) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $apiData);
		}
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $apiRes = curl_exec($ch);
        
        if (!curl_errno($ch)) {
            $response_info = curl_getinfo($ch);
            if ($response_info['http_code'] != 200) {
                throw new \Exception('[Invalid request] Code: '. $response_info['http_code'].' Error: '.strval($apiRes));
            }
        }

    	curl_close($ch);

        return $apiRes;
    }
}