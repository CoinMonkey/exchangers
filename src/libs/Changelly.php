<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\interfaces\OrderInterface;
use coinmonkey\interfaces\AmountInterface;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Status;
use coinmonkey\exchangers\tools\Changelly as ChangellyTool;

class Changelly implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;

    const STRING_ID = 'changelly';
    const EXCHANGER_TYPE = 'instant';

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

    public function getExchangeStatus($id) : Status
    {
        $statusId = $this->tool->request('getStatus', ['id' => $id]);

        switch($statusId) {
            case 'waiting': $status = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'confirming': $status = OrderExchange::STATUS_YOU_DID_TRANSACTION; break;
            case 'exchanging': $status = OrderExchange::STATUS_EXCHANGER_PROCESSING; break;
            case 'sending': $status = OrderExchange::STATUS_WAIT_EXCHANGER_TRANSACTION; break;
            case 'finished': $status = OrderExchange::STATUS_DONE; break;
            default: $status = OrderExchange::STATUS_FAIL; break;
        }

        $tx1 = null;
        $tx2 = null;

        if($status == OrderExchange::STATUS_DONE) {
            if($txes = $this->tool->request('getTransactions', ['extraId' => $id, "limit" => 10, "offset" => 0])) {
                foreach($txes as $key => $tx) {
                    if($key == 1) $tx1 = $tx->payoutHash;
					if($key == 2) $tx2 = $tx->payoutHash;
				}
            }
        }

        return new Status($status, $tx1, $tx2);
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        $minimum = $this->getMinimum($amount, $coin2);

        if($amount->getAmount() < $minimum) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $minimum . ' ' . $amount->getCoin()->getCode());
        }

        $cost = $this->tool->request('getExchangeAmount', ['from' => $amount->getCoin()->getCode(), 'to' => $coin2->getCode(), 'amount' => $amount->getAmount()]);

        return new Amount(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getMinimum($coin->getCode(), $coin2->getCode());
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return 0;
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        $res = $this->tool->request('createTransaction', ['amount' => $amount->getAmount(), 'from' => $amount->getCoin()->getCode(), 'to' => $coin2->getCode(), 'address' => $clientAddress]);

        return [
            'address' => $res->payinAddress,
            'id' => $res->id,
        ];
    }


    private function getMinimum(AmountInterface $amount, CoinInterface $coin2)
    {
        $min = $this->tool->request('getMinAmount', ['from' => $amount->getCoin()->getCode(), 'to' => $coin2->getCode()]);

        return $min;
    }
}
