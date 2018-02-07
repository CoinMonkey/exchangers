<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\interfaces\OrderInterface;
use coinmonkey\interfaces\AmountInterface;
use \Achse\ShapeShiftIo\Client;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Status;

class ShapeShift implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;
    private $ratesCache = [];

    CONST STRIND_ID = 'shapeshift';
    const EXCHANGER_TYPE = 'instant';

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

    public function getExchangeStatus($id) : ?Status
    {
        $order = $this->tool->getStatusOfDepositToAddress($id);

        switch($order->status) {
            case 'no_deposits': $status = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'received': $status = OrderExchange::STATUS_WAIT_EXCHANGER_TRANSACTION; break;
            case 'complete': $status = OrderExchange::STATUS_DONE; break;
            default: $status = OrderExchange::STATUS_FAIL; break;
        }

        $tx1 = null;
        $tx2 = null;

        if(isset($order->transaction)) {
            $tx2 = $order->transaction;
        }

        return new Status($status, $tx1, $tx2);
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        $rate = $this->getRates($amount->getCoin()->getCode() . '-' . $coin2->getCode());

        if($amount->getAmount() > $rate['maximum'] | $amount->getAmount() < $rate['minimum']) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $rate['minimum'] . ' ' . $amount->getCoin()->getCode() . ' and maximum is ' . $rate['maximum'] . ' ' . $amount->getCoin()->getCode());
        }

        if($rate['rate'] > 0) {
            $cost = $amount->getAmount()*$rate['rate'];
        } else {
            $cost = $amount->getAmount()*(1/$rate['rate']);
        }

        $cost = $cost-$rate['minerFee'];

        return new Amount(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        $rate = $this->getRates($amount->getCoin()->getCode() . '-' . $coin2->getCode());

        return $rate['minimum'];
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        $rate = $this->getRates($amount->getCoin()->getCode() . '-' . $coin2->getCode());

        return $rate['maximum'];
    }

    public function getRates(string $market)
    {
        if($this->cache && isset($this->ratesCache[$market])) {
            return $this->ratesCache[$market];
        }

        $currencies = explode('-', $market);
        $rate = (array) $this->tool->getMarketInfo($currencies[0], $currencies[1]);

        $this->ratesCache[$market] = [
            'rate' => $rate['rate'],
            'minimum' => $rate['minimum'],
            'minerFee' => $rate['minerFee'],
            'maximum' => $rate['limit'],
        ];

        return $this->ratesCache[$market];
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        try {
            $transaction = $this->tool->createTransaction($clientAddress, $amount->getCoin()->getCode(), $coin2->getCode(), null, null, null, $this->key);
        } catch(\Exception $e) {
            throw new \coinmonkey\exceptions\ErrorException($e->getMessage(), 'Shapeshift makeDepositAddress error');
        }

        return [
            'address' => $transaction->deposit,
            'id' => $transaction->orderId,
        ];
    }
}
