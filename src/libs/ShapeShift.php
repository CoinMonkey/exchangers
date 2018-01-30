<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Amount;
use \Achse\ShapeShiftIo\Client;
use coinmonkey\entities\Coin;
use coinmonkey\entities\Order as OrderExchange;

class ShapeShift implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;

    CONST STRIND_ID = 'shapeshift';

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new Client();
        $this->cache = $cache;
    }

    public function getId() : string
    {
        return self::STRIND_ID;
    }

    public function getExchangeStatus(OrderExchange $order) : ?integer
    {
        $address = $order->getAddress();
        $return = $this->tool->getStatusOfDepositToAddress($address->getExchangerOrderId());

        switch($return->status) {
            case 'no_deposits': return OrderExchange::STATUS_WAIT_YOUR_TRANSACTION;
            case 'received': return OrderExchange::STATUS_WAIT_EXCHANGER_TRANSACTION;
            case 'complete': return OrderExchange::STATUS_DONE;
            default: return OrderExchange::STATUS_FAIL;
        }

        return null;
    }

    public function getEstimateAmount(Amount $amount, Coin $coin2) : Order
    {
        $rate = $this->getRates($amount->getCoin()->code . '-' . $coin2->code);

        if($amount->getGivenAmount() > $rate['maximum'] | $amount->getGivenAmount() < $rate['minimum']) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $rate['minimum'] . ' ' . $amount->getCoin()->getCode() . ' and maximum is ' . $rate['maximum'] . ' ' . $amount->getCoin()->getCode());
        }

        if($rate['last'] > 0) {
            $cost = $amount->getGivenAmount()*$rate['last'];
        } else {
            $cost = $amount->getGivenAmount()*(1/$rate['last']);
        }

        $cost = $cost-$rate['minerFee'];

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function makeDepositAddress(string $clientAddress, Amount $amount, Coin $coin2) : array
    {
        try {
            $transaction = $this->tool->createTransaction($clientAddress, $amount->getCoin()->getCode(), $coin2->getCode(), null, null, null, $this->key);
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
