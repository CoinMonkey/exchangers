<?php

namespace coinmonkey\exchangers;

use coinmonkey\interfaces\ExchangerFabricInterface;

class ExchangerFabric implements ExchangerFabricInterface
{
    public function build($name)
    {
        switch($name) {
            case 'changer': return new libs\Changer(env('CHANGER_API_NAME'), env('CHANGER_API_KEY'), env('CHANGER_API_SECURE'), $cache);
            case 'changelly': return new libs\Changelly(env('CHANGELLY_API_KEY'), env('CHANGELLY_API_SECRET'), $cache);
            case 'shapeshift': return new libs\ShapeShift(env('SHAPESHIFT_API_KEY'), env('SHAPESHIFT_API_SECRET'), $cache);
            case 'poloniex': return new libs\Poloniex(env('POLONIEX_API_KEY'), env('POLONIEX_API_SECRET'), $cache);
            case 'bittrex': return new libs\Bittrex(env('BITTREX_API_KEY'), env('BITTREX_API_SECRET'), $cache);
            case 'bitfinex': return new libs\Bitfinex(env('BITFINEX_API_KEY'), env('BITFINEX_API_SECRET'), $cache);
            case 'evercoin': return new libs\Evercoin(env('EVERCOIN_API_KEY'), $cache);
            case 'yobit': return new libs\Yobit(env('YOBIT_API_KEY'), env('YOBIT_API_SECRET'), $cache);
        }
    }
}