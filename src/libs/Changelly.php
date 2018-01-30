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
        $return = $this->tool->request('getStatus', ['id' => $address->getExchangerOrderId()]);

        $ourStatus = null;
        $shapeStatus = $return;

        switch($shapeStatus) {
            case 'waiting': $ourStatus = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'confirming': $ourStatus = OrderExchange::STATUS_YOU_DID_TRANSACTION; break;
            case 'exchanging': $ourStatus = OrderExchange::STATUS_EXCHANGER_PROCESSING; break;
            case 'sending': $ourStatus = OrderExchange::STATUS_WAIT_EXCHANGER_TRANSACTION; break;
            case 'finished': $ourStatus = OrderExchange::STATUS_DONE; break;
            default: $ourStatus = OrderExchange::STATUS_FAIL; break;
        }

        if($order->status != $ourStatus) {
            $order->writeLog($ourStatus);
        }

        if($transaction = $order->getTransaction($order->coin2)) {
            $info = $this->tool->request('getTransactions', ['address' => $address->getExchangerOrderId(), "limit" => 10, "offset" => 0]);

            if($info) {
                foreach($info as $tx) {
                    $transaction->hash = $tx->payoutHash;
                    $transaction->save();
                    break;
                }
            }
        }

        return (new Status($ourStatus, (isset($return->transaction)) ? $return->transaction : null));
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
