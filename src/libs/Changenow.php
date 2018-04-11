<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\exchangers\tools\Changenow as Tool;
use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\interfaces\OrderInterface;
use coinmonkey\interfaces\AmountInterface;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Status;

class Changenow implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;

    CONST STRIND_ID = 'changenow';
    const EXCHANGER_TYPE = 'instant';

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->tool = new Tool($key);
        $this->cache = $cache;
    }

    public function getId() : string
    {
        return self::STRIND_ID;
    }

    public function getExchangeStatus($id, $payInAddress) : ?Status
    {
        if(!$order = $this->tool->getTransactionStatus($id)) {
            throw new \coinmonkey\exceptions\ErrorException('Order doesnt\'t exists');
        }

        switch($order->status) {
            case 'new': $status = Status::STATUS_WAIT_CLIENT_TRANSACTION; break;
            case 'waiting': $status = Status::STATUS_WAIT_CLIENT_TRANSACTION; break;
            case 'confirming': $status = Status::STATUS_WAIT_EXCHANGER_PROCESSING; break;
            case 'exchanging': $status = Status::STATUS_EXCHANGER_PROCESSING; break;
            case 'sending': $status = Status::STATUS_WAIT_EXCHANGER_TRANSACTION; break;
            case 'finished': $status = Status::STATUS_DONE; break;
            default: $status = Status::STATUS_FAIL; break;
        }

        $tx1 = null;
        $tx2 = null;

        if(isset($order->payinHash) && $order->payinHash) {
            $tx1 = $order->payinHash;
        }
        
        if(isset($order->payoutHash) && $order->payoutHash) {
            $tx2 = $order->payoutHash;
        }

        return new Status($status, $tx1, $tx2);
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        if($amount->getAmount() < $this->getMinAmount($amount->getCoin(), $coin2)) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $min . ' ' . $amount->getCoin()->getCode());
        }

        if(!$cost = $this->tool->getExchangeAmount($amount->getCoin()->getCode(), $coin2->getCode(), $amount->getAmount())) {
            throw new \coinmonkey\exceptions\ErrorException('Changenow doesn\t support this par');
        }
        
        return new Amount(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getMinAmount($coin->getCode(), $coin2->getCode());
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return 99999999;
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        try {
            if(!$transaction = $this->tool->createTransaction($amount->getCoin()->getCode(), $coin2->getCode(), $clientAddress, $amount->getAmount())) {
                throw new \coinmonkey\exceptions\ErrorException('Please check out your address');
            }
        } catch(\Exception $e) {
            throw new \coinmonkey\exceptions\ErrorException($e->getMessage());
        }

        return [
            'address' => $transaction->payinAddress,
            'id' => $transaction->id,
        ];
    }
}
