<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\interfaces\OrderInterface;
use coinmonkey\interfaces\AmountInterface;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Status;
use coinmonkey\exchangers\tools\Nexchange as NexchangeTool;

class Nexchange implements InstantExchangerInterface
{
    private $referral = '';
    private $tool;
    private $cache;

    const STRING_ID = 'nexchange';
    const EXCHANGER_TYPE = 'instant';

    public function __construct($referral, $cache = true)
    {
        $this->referral = $referral;
        $this->cache = $cache;
        $this->tool = new NexchangeTool($referral);
    }

    public function getId() : string
    {
        return self::STRING_ID;
    }

    public function withdraw(string $address, AmountInterface $amount)
    {
        return null;
    }

    public function getExchangeStatus($id, $payInAddress) : ?Status
    {
        $nxOrder = $this->tool->getOrder($id);

        $status = Status::STATUS_WAIT_CLIENT_TRANSACTION;

        if(isset($nxOrder->status_name)) {
            switch($nxOrder->status_name[0][0]) {
                case '11': $status = Status::STATUS_WAIT_CLIENT_TRANSACTION; break;
                case '12': $status = Status::STATUS_WAIT_EXCHANGER_PROCESSING; break;
                case '13': $status = Status::STATUS_EXCHANGER_PROCESSING; break;
                case '14': $status = Status::STATUS_EXCHANGER_PROCESSING; break;
                case '15': $status = Status::STATUS_DONE; break;
                default: $status = Status::STATUS_FAIL; break;
            }
        }

        $tx1 = null;
        $tx2 = null;

        if($status == Status::STATUS_DONE && !isset($nxOrder->transactions)) {
            $status = Status::STATUS_WAIT_EXCHANGER_TRANSACTION;
        }

        if(isset($nxOrder->transactions)) {
            foreach($nxOrder->transactions as $nctrx) {
                if($status == Status::STATUS_WAIT_CLIENT_TRANSACTION && $nctrx->type == 'D') {
                    $status = Status::STATUS_WAIT_EXCHANGER_PROCESSING;
                }

                if($nctrx->type == 'W') {
                    $tx2 = $nctrx->tx_id;
                } else {
                    $tx1 = $nctrx->tx_id;
                }
            }
        }

        return new Status($status, $tx1, $tx2);
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        $minimum = $this->tool->getMinimum($amount->getCoin()->getCode());
        $maximum = $this->tool->getMaximum($coin2->getCode());

        if($amount->getAmount() > $maximum | $amount->getAmount() < $minimum) {
            throw new \App\Exceptions\ErrorException('Minimum is ' . $minimum . ' ' . $amount->getCoin()->getCode() . ' and maximum is ' . $maximum . ' ' . $amount->getCoin()->getCode(), null, null, 0);
        }

        $cost = $this->tool->getPrice($amount->getCoin()->getCode(), $coin2->getCode(), $amount->getAmount());

        $cost = $cost-$this->tool->getWithdrawalFee($coin2->getCode());

        return new Amount(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getMinimum($coin->getCode());
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getMaximum($coin->getCode());
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        $res = $this->tool->createAnonymousOrder($amount->getCoin()->getCode(), $coin2->getCode(), $amount->getAmount(), $clientAddress);

        if(!isset($res->deposit_address)) {
            if(isset($res->non_field_errors)) {
                throw new \coinmonkey\exceptions\ErrorException(htmlspecialchars($res->non_field_errors[0]));
            }

            throw new \coinmonkey\exceptions\ErrorException('Can not make address for deposit');
        }

        return [
            'address' => $res->deposit_address->address,
            'id' => $res->unique_reference,
        ];
    }

    public function getMinConfirmations(CoinInterface $coin)
    {
        return $this->tool->getMinConfirmations($coin->getCode());
    }
}
