<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Coin;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\exchangers\tools\Nexchange;

class Nexchange implements InstantExchangerInterface
{
    private $referral = '';
    private $tool;
    private $cache;

    const STRING_ID = 'nexchange';

    public function __construct($referral, $cache = true)
    {
        $this->referral = $referral;
        $this->cache = $cache;
        $this->tool = new Nexchange($referral);
    }

    public function getId() : string
    {
        return self::STRIND_ID;
    }

    public function withdraw(string $address, Amount $amount)
    {
        return null;
    }

    public function getExchangeStatus(OrderExchange $order) : ?integer
    {
        $address = $order->getAddress();

        $nxOrder = $this->tool->getOrder($address->getExchangerOrderId());

        switch($nxOrder->status_name[0][0]) {
            case '11': return OrderExchange::STATUS_WAIT_CLIENT_TRANSACTION;
            case '12': return OrderExchange::STATUS_WAIT_EXCHANGER_PROCESSING;
            case '13': return OrderExchange::STATUS_EXCHANGER_PROCESSING;
            case '15': return OrderExchange::STATUS_DONE;
            default: return OrderExchange::STATUS_DONE;
        }

        return null;
    }

    public function getEstimateAmount(Amount $amount, Coin $coin2) : Order
    {
        $minimum = $this->tool->getMinimum($amount->getCoin()->getCode());
        $maximum = $this->tool->getMaximum($coin2->getCode());

        if($amount->getGivenAmount() > $maximum | $amount->getGivenAmount() < $minimum) {
            throw new \App\Exceptions\ErrorException('Minimum is ' . $minimum . ' ' . $amount->getCoin()->getCode() . ' and maximum is ' . $maximum . ' ' . $amount->getCoin()->getCode(), null, null, 0);
        }

        $cost = $this->tool->getPrice($amount->getCoin()->getCode(), $coin2->getCode(), $amount->getGivenAmount());

        $cost = $cost-$this->tool->getWithdrawalFee($coin2->getCode());

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function makeDepositAddress(string $clientAddress, Amount $amount, Coin $coin2) : array
    {
        $res = $this->tool->createAnonymousOrder($amount->getCoin()->getCode(), $coin2->getCode(), $amount->getGivenAmount(), $clientAddress);

        return [
            'private' => null,
            'public' => null,
            'address' => $res->deposit_address->address,
            'id' => $res->unique_reference,
        ];
    }

    public function getMinConfirmations(Coin $coin)
    {
        return $this->tool->getMinConfirmations($coin->getCode());
    }
}
