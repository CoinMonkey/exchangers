<?php

namespace coinmonkey\exchangers;

use coinmonkey\interfaces\ExchangerFabricInterface;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\interfaces\ExchangerInterface;

class ExchangerFabric implements ExchangerFabricInterface
{
    private $config = [];
    private $name = null;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function buildInstant($exchangerName, $cache = true) :  InstantExchangerInterface
    {
        $this->exchangerName = $exchangerName;

        switch($exchangerName) {
            case 'changer': return new libs\Changer($this->getConfig('CHANGER_API_NAME'), $this->getConfig('CHANGER_API_KEY'), $this->getConfig('CHANGER_API_SECURE'), $cache);
            case 'nexchange': return new libs\Nexchange($this->getConfig('CHANGER_API_NAME'), $this->getConfig('CHANGER_API_KEY'), $this->getConfig('CHANGER_API_SECURE'), $cache);
            case 'changelly': return new libs\Changelly($this->getConfig('CHANGELLY_API_KEY'), $this->getConfig('CHANGELLY_API_SECRET'), $cache);
            case 'shapeshift': return new libs\ShapeShift($this->getConfig('SHAPESHIFT_API_KEY'), $this->getConfig('SHAPESHIFT_API_SECRET'), $cache);
            case 'changenow': return new libs\Changenow($this->getConfig('CHANGENOW_API_KEY'), $this->getConfig('CHANGENOW_API_SECRET'), $cache);
            case 'poloniex': return new libs\Poloniex($this->getConfig('POLONIEX_API_KEY'), $this->getConfig('POLONIEX_API_SECRET'), $cache);
            case 'bittrex': return new libs\Bittrex($this->getConfig('BITTREX_API_KEY'), $this->getConfig('BITTREX_API_SECRET'), $cache);
        }
    }

    public function build($exchangerName, $cache = true) :  InstantExchangerInterface
    {
        $this->exchangerName = $exchangerName;

        switch($exchangerName) {
            case 'poloniex': return new libs\Poloniex($this->getConfig('POLONIEX_API_KEY'), $this->getConfig('POLONIEX_API_SECRET'), $cache);
            case 'bittrex': return new libs\Bittrex($this->getConfig('BITTREX_API_KEY'), $this->getConfig('BITTREX_API_SECRET'), $cache);
        }
    }

    public function getName() : string
    {
        return $this->name;
    }

    private function getConfig($key)
    {
        return $this->config[$key];
    }
}
