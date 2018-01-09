<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Sum;
use coinmonkey\exchangers\tools\Changelly as ChangellyTool;
use coinmonkey\entities\Currency;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Status;

class Changelly implements InstantExchangerInterface
{
    private $key = '';
    private $secret = '';
    private $tool;
    private $cache;

    public function getId() : string
    {
        return 'changelly';
    }

    public function __construct($key, $secret, $cache = true)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->tool = new ChangellyTool($key, $secret, $cache);
        $this->cache = $cache;
    }

    public function getStatus(OrderExchange $order) : Status
    {
        $wallet = $order->getWallet()->first();
        $return = $this->tool->request('getStatus', ['id' => $wallet->external_id]);

        $ourStatus = null;
        $shapeStatus = $return;

        switch($shapeStatus) {
            case 'waiting': $ourStatus = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'confirming': $ourStatus = OrderExchange::STATUS_YOU_DID_TRANSACTION; break;
            case 'exchanging': $ourStatus = OrderExchange::STATUS_PARTNER_PROCESSING; break;
            case 'sending': $ourStatus = OrderExchange::STATUS_WAIT_PARTNER_TRANSACTION; break;
            case 'finished': $ourStatus = OrderExchange::STATUS_DONE; break;
            default: $ourStatus = OrderExchange::STATUS_FAIL; break;
        }

        if($order->status != $ourStatus) {
            $order->writeLog($ourStatus);
		}

		if($transaction = $order->getTransaction($order->currency2)) {
			$info = $this->tool->request('getTransactions', ['address' => $wallet->address, "limit" => 10, "offset" => 0]);

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

    public function getEstimateAmount(Sum $sum, Currency $currency2) : Order
    {
        $minimum = $this->getMinimum( $sum, $currency2);

        if($sum->getSum() < $minimum) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $minimum . ' ' . $sum->getCurrency()->getCode());
        }

        $cost = $this->tool->request('getExchangeAmount', ['from' => $sum->getCurrency()->getCode(), 'to' => $currency2->getCode(), 'amount' => $sum->getSum()]);

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $currency2);
    }

    public function makeDepositAddress(string $clientAddress, Sum $sum, Currency $currency2) : array
    {
        $res = $this->tool->request('createTransaction', ['amount' => $sum->getSum(), 'from' => $sum->getCurrency()->getCode(), 'to' => $currency2->getCode(), 'address' => $clientAddress]);

        return [
            'private' => null,
            'public' => null,
            'address' => $res->payinAddress,
            'id' => $res->id,
        ];
    }
    

    private function getMinimum(Sum $sum, Currency $currency2)
    {
        $min = $this->tool->request('getMinAmount', ['from' => $sum->getCurrency()->getCode(), 'to' => $currency2->getCode()]);

        return $min;
    }
}
