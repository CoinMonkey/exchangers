<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Sum;
use coinmonkey\entities\Status;
use \Achse\ShapeShiftIo\Client;
use coinmonkey\entities\Currency;
use coinmonkey\entities\Order as OrderExchange;

class ShapeShift implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new Client();
        $this->cache = $cache;
    }

    public function getId() : string
    {
        return 'shapeshift';
    }

    public function getStatus(OrderExchange $order) : Status
    {
        $wallet = $order->getWallet()->first();
        $return = $this->tool->getStatusOfDepositToAddress($wallet->address);

        $ourStatus = null;
        $shapeStatus = $return->status;

        switch($shapeStatus) {
            case 'no_deposits': $ourStatus = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'received': $ourStatus = OrderExchange::STATUS_WAIT_PARTNER_TRANSACTION; break;
            case 'complete': $ourStatus = OrderExchange::STATUS_DONE; break;
            default: $ourStatus = OrderExchange::STATUS_FAIL; break;
        }

        if($order->status != $ourStatus) {
            $order->writeLog($ourStatus);

            if(isset($return->transaction)) {
                if($transaction = $order->getTransaction($order->currency2)) {
                    $transaction->hash = $return->transaction;
                    $transaction->save();
                }
            }
        }

        return (new Status($ourStatus, (isset($return->transaction)) ? $return->transaction : null));
    }
    
    public function getEstimateAmount(Sum $sum, Currency $currency2) : Order
    {
        $rate = $this->getRates($sum->getCurrency()->code . '-' . $currency2->code);

        if($sum->getSum() > $rate['maximum'] | $sum->getSum() < $rate['minimum']) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $rate['minimum'] . ' ' . $sum->getCurrency()->getCode() . ' and maximum is ' . $rate['maximum'] . ' ' . $sum->getCurrency()->getCode());
        }

        if($rate['last'] > 0) {
            $cost = $sum->getSum()*$rate['last'];
        } else {
            $cost = $sum->getSum()*(1/$rate['last']);
        }

        $cost = $cost-$rate['minerFee'];

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $currency2);
    }

    public function makeDepositAddress(string $clientAddress, Sum $sum, Currency $currency2) : array
    {
        try {
            $transaction = $this->tool->createTransaction($clientAddress, $sum->getCurrency()->getCode(), $currency2->getCode(), null, null, null, $this->key);
        } catch(\Exception $e) {
            throw new \coinmonkey\exceptions\ErrorException($e->getMessage(), 'Shapeshift makeDepositAddress error');
        }

        return [
            'private' => null,
            'public' => null,
            'address' => $transaction->deposit,
            'id' => $transaction->orderId,
        ];
    }
}
