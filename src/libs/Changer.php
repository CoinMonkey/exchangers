<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\interfaces\OrderInterface;
use coinmonkey\interfaces\AmountInterface;
use coinmonkey\interfaces\CoinInterface;
use coinmonkey\entities\Order as OrderExchange;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Status;
use coinmonkey\exchangers\tools\Changer as ChangerTool;
use coinmonkey\exchangers\tools\ChangerAuth;

class Changer implements InstantExchangerInterface
{
    private $name = '';
    private $key = '';
    private $secure;
    private $cache;
    private $tool;

    const STRING_ID = 'changer';
    const EXCHANGER_TYPE = 'instant';

    public function getId() : string
    {
        return self::STRIND_ID;
    }

    public function __construct($name, $key, $secure, $cache = null)
    {
        $this->name = $name;
        $this->key = $key;
        $this->secure = $secure;
        $this->cache = $cache;
        $this->tool = new ChangerTool(new ChangerAuth($key, $secure));
    }

    public function getExchangeStatus($id) : ?Status
    {
        $statusData = $this->tool->checkExchange($id);

        switch($statusData->status) {
            case 'new': $status = OrderExchange::STATUS_WAIT_YOUR_TRANSACTION; break;
            case 'processing': $status = OrderExchange::STATUS_EXCHANGER_PROCESSING; break;
            case 'processed': $status = OrderExchange::STATUS_DONE; break;
            default: $status = OrderExchange::STATUS_FAIL; break;
        }

        $tx1 = null;
        $tx2 = null;

        if(isset($statusData->batch_out) && !empty($statusData->batch_out)) {
            $tx2 = $statusData->batch_out;
        }

        return new Status($status, $tx1, $tx2);
    }

    public function getEstimateAmount(AmountInterface $amount, CoinInterface $coin2) : AmountInterface
    {
        $give = $this->getCoinName($amount->getCoin()->getCode());
        $get = $this->getCoinName($coin2->getCode());
        if(!$give | !$get) {
            throw new \coinmonkey\exceptions\ErrorException('Market don\'t exists');
        }
        $limits = $this->tool->getLimits($give, $get);

        if($amount->getAmount() < $limits->limits->min_amount | $amount->getAmount() > $limits->limits->max_amount) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $limits->limits->min_amount . ' ' . $amount->getCoin()->getCode() . ' and maximum is ' . $limits->limits->max_amount . ' ' . $amount->getCoin()->getCode());
        }

        $rate = $this->tool->getRate($give, $get);

        $cost = round($amount->getAmount()*$rate->rate, 8);

        return new Amount(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function getMinAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getLimits($coin->getCode(), $coin2->getCode())->min_amount;
    }

    public function getMaxAmount(CoinInterface $coin, CoinInterface $coin2) : ?int
    {
        return $this->tool->getLimits($coin->getCode(), $coin2->getCode())->max_amount;
    }

    public function makeDepositAddress(string $clientAddress, AmountInterface $amount, CoinInterface $coin2) : array
    {
        $data = [
            'send' => $this->getCoinName($amount->getCoin()->getCode()),
            'receive' => $this->getCoinName($coin2->getCode()),
            'amount' => $amount->getAmount(),
            'receiver_id' => $clientAddress,
        ];

        $res = $this->tool->makeExchange($data);

        return [
            'address' => $res->payee,
            'id' => $res->exchange_id,
        ];
    }

    private function getCoinName($code)
    {
        $data = [
            'BTC' => 'bitcoin_BTC',
            'ETH' => 'ethereum_ETH',
            'BCH' => 'bitcoincash_BCH',
            'XRP' => 'ripple_XRP',
            'DASH' => 'dash_DASH',
            'XMR' => 'monero_XMR',
            'ZEC' => 'zcash_ZEC',
            'LTC' => 'litecoin_LTC',
            'DOGE' => 'dogecoin_DOGE',
            'ETC' => 'ethereumclassic_ETC',
            'REP' => 'augur_REP',
            'GNT' => 'golem_GNT',
            'GNO' => 'gnosis_GNO',
            'LSK' => 'lisk_LSK',
            'PPC' => 'peercoin_PPC'
        ];

        return (isset($data[$code])) ? $data[$code] : false;
    }
}
