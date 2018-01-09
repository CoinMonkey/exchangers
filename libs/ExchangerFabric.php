<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\ExchangerFabricInterface;
use coinmonkey\interfaces\ExchangerInterface;
use coinmonkey\exchangers\Bitfinex;
use coinmonkey\exchangers\Bittrex;
use coinmonkey\exchangers\Poloniex;

class ExchangerFabric implements ExchangerFabricInterface
{
    public function build($exchangerName) : ExchangerInterface
    {
        switch($exchangerName) {
            case 'bittrex':
                return new Bittrex(env('BITTREX_API_KEY'), env('BITTREX_API_SECRET'));
            case 'bitfinex':
                return new Bitfinex(env('BITFINEX_API_KEY'), env('BITFINEX_API_SECRET'));
            case 'poloniex':
                return new Poloniex(env('POLONIEX_API_KEY'), env('POLONIEX_API_SECRET'));
            default:
                throw new \coinmonkey\exceptions\ErrorException('Exchanger doen\'t exists.');
        }

        return new Bittrex(env('BITTREX_API_KEY'), env('BITTREX_API_SECRET'));
    }
}