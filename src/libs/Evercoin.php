<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Sum;
use coinmonkey\entities\Currency;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\exchangers\tools\Evercoin as EvercoinTool;
use coinmonkey\entities\Status;
use coinmonkey\entities\ExchangeEstimate;

class Evercoin implements InstantExchangerInterface
{
    private $key = '';
    private $cache;
    private $tool;

    public function getId() : string
    {
        return 'changer';
    }

    public function __construct($key, $cache = null)
    {
        $this->key = $key;
        $this->tool = new EvercoinTool($key);
    }

    public function getSignature()
    {
        return $this->tool->getSignature();
    }

    public function getStatus(OrderExchange $order) : Status
    {
        $wallet = $order->getWallet()->first();
        $return = $this->tool->checkExchange($wallet->external_id);

        $ourStatus = null;
        $status = $return->status;

        switch($status) {
            case 'new': $ourStatus = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'processing': $ourStatus = OrderExchange::STATUS_PARTNER_PROCESSING; break;
            case 'processed': $ourStatus = OrderExchange::STATUS_DONE; break;
            default: $ourStatus = OrderExchange::STATUS_FAIL; break;
        }

        if($order->status != $ourStatus) {
            $order->writeLog($ourStatus);
        }

        if(isset($return->batch_out) && $transaction = $order->getTransaction($order->currency2)) {
            if($return->batch_out) {
                $transaction->hash = $return->batch_out;
                $transaction->save();
            }
        }

        return (new Status($ourStatus, (isset($return->batch_out)) ? $return->batch_out : null));
    }

    public function getEstimateAmount(Sum $sum, Currency $currency2) : Order
    {
        try {
            $amount = $this->tool->getPrice($sum->getCurrency()->code, $currency2->code, $sum->getSum());
        } catch(\Exception $e) {
            throw new \coinmonkey\exceptions\ErrorException($e->getMessage());
        }
        
        return new Order(round($amount, 8, PHP_ROUND_HALF_UP), $currency2);
    }

    public function makeDepositAddress(string $clientAddress, Sum $sum, Currency $currency2) : array
    {
        $sugnature = null;
        $getEstimate = null;
        
        if($promice = ExchangeEstimate::where('give', $sum->getSum())->where('from_currency', $sum->getCurrency()->getCode())->where('to_currency', $currency2->getCode())->where('exchanger', 'evercoin')->orderBy('id', 'desc')->first()) {
            $signature = $promice->signature;
            $getEstimate = $promice->get_estimate;
        }

        $res = $this->tool->createOrder($sum->getCurrency()->getCode(), $currency2->getCode(), $sum->getSum(), $getEstimate, $clientAddress, $signature);

        return [
            'private' => null,
            'public' => null,
            'address' => $res->payee,
            'id' => $res->exchange_id,
        ];
    }
    
    private function getCurrencyName($code) {
        $data = [
            'BTC' => 'bitcoin_BTC',
            'ETH' => 'ethereum_ETH',
            'BCH' => 'bitcoincash_BCH',
            'XRP' => 'ripple_XRP',
            'DASH' => 'dash_DASH',
            'XMR' => 'monero_XMR',
            'ZEC' => 'zcash_ZEC',
            'LTC' => 'litecoin_LTC',
            'DOGE' => 'dogecoin_DOGE',
            'ETC' => 'ethereumclassic_ETC',
            'REP' => 'augur_REP',
            'GNT' => 'golem_GNT',
            'GNO' => 'gnosis_GNO',
            'LSK' => 'lisk_LSK',
            'PPC' => 'peercoin_PPC'
        ];
        
        return (isset($data[$code])) ? $data[$code] : false;
    }
}
