<?php
namespace coinmonkey\exchangers\tools;

class ChangerAuth
{
    protected $_ApiKey;
    protected $_ApiSecure;
    protected $_ApiTimestamp;

    function __construct($ApiKey, $ApiSecure, $ApiTimestamp = 0) {
        $this->_ApiKey = $ApiKey;
        $this->_ApiSecure = $ApiSecure;
        $this->_ApiTimestamp = $ApiTimestamp;
    }
    
    public function getApiKey() {
        return $this->_ApiKey;
    }
    
    public function getApiSecure() {
        return $this->_ApiSecure;
    }
    
    public function getApiTimestamp() {   
        if($this->_ApiTimestamp)
            return $this->_ApiTimestamp;
        else
            return time();
    }
}