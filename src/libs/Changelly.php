<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Amount;
use coinmonkey\exchangers\tools\Changelly as ChangellyTool;
use coinmonkey\entities\Coin;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Status;

class Changelly implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;

    const STRING_ID = 'changelly';

    public function getId() : string
    {
        return self::STRIND_ID;
    }

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new ChangellyTool($key, $secret, $cache);
        $this->cache = $cache;
    }

    public function getExchangeStatus(OrderExchange $order) : ?integer
    {
        $address = $order->getAddress();
        $status = $this->tool->request('getStatus', ['id' => $address->getExchangerOrderId()]);

        switch($status) {
            case 'waiting': return OrderExchange::STATUS_WAIT_YOUR_TRANSACTION;
            case 'confirming': return OrderExchange::STATUS_YOU_DID_TRANSACTION;
            case 'exchanging': return OrderExchange::STATUS_EXCHANGER_PROCESSING;
            case 'sending': return OrderExchange::STATUS_WAIT_EXCHANGER_TRANSACTION;
            case 'finished': return OrderExchange::STATUS_DONE;
            default: return OrderExchange::STATUS_FAIL;
        }

        return null;
    }

    public function getEstimateAmount(Amount $amount, Coin $coin2) : Order
    {
        $minimum = $this->getMinimum( $amount, $coin2);

        if($amount->getGivenAmount() < $minimum) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $minimum . ' ' . $amount->getCoin()->getCode());
        }

        $cost = $this->tool->request('getExchangeAmount', ['from' => $amount->getCoin()->getCode(), 'to' => $coin2->getCode(), 'amount' => $amount->getGivenAmount()]);

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function makeDepositAddress(string $clientAddress, Amount $amount, Coin $coin2) : array
    {
        $res = $this->tool->request('createTransaction', ['amount' => $amount->getGivenAmount(), 'from' => $amount->getCoin()->getCode(), 'to' => $coin2->getCode(), 'address' => $clientAddress]);

        return [
            'private' => null,
            'public' => null,
            'address' => $res->payinAddress,
            'id' => $res->id,
        ];
    }


    private function getMinimum(Amount $amount, Coin $coin2)
    {
        $min = $this->tool->request('getMinAmount', ['from' => $amount->getCoin()->getCode(), 'to' => $coin2->getCode()]);

        return $min;
    }
}
