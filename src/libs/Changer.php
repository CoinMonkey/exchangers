<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Sum;
use coinmonkey\entities\Currency;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\exchangers\tools\Changer as ChangerTool;
use coinmonkey\exchangers\tools\ChangerAuth;
use coinmonkey\entities\Status;

class Changer implements InstantExchangerInterface
{
    private $name = '';
    private $key = '';
    private $secure;
    private $cache;
    private $tool;

    public function getId() : string
    {
        return 'changer';
    }

    public function __construct($name, $key, $secure, $cache = null)
    {
        $this->name = $name;
        $this->key = $key;
        $this->secure = $secure;
        $this->cache = $cache;
        $this->tool = new ChangerTool(new ChangerAuth($key, $secure));
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
        $give = $this->getCurrencyName($sum->getCurrency()->getCode());
        $get = $this->getCurrencyName($currency2->getCode());
        if(!$give | !$get) {
            throw new \coinmonkey\exceptions\ErrorException('Market don\'t exists');
        }
        $limits = $this->tool->getLimits($give, $get);

        if($sum->getSum() < $limits->limits->min_amount | $sum->getSum() > $limits->limits->max_amount) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $limits->limits->min_amount . ' ' . $sum->getCurrency()->getCode() . ' and maximum is ' . $limits->limits->max_amount . ' ' . $sum->getCurrency()->getCode());
        }

        $rate = $this->tool->getRate($give, $get);

        $cost = round($sum->getSum()*$rate->rate, 8);

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $currency2);
    }

    public function makeDepositAddress(string $clientAddress, Sum $sum, Currency $currency2) : array
    {
        $data = [
            'send' => $this->getCurrencyName($sum->getCurrency()->getCode()),
            'receive' => $this->getCurrencyName($currency2->getCode()),
            'amount' => $sum->getSum(),
            'receiver_id' => $clientAddress,
        ];
        
        $res = $this->tool->makeExchange($data);

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
