<?php

namespace coinmonkey\exchangers\libs;

use coinmonkey\interfaces\InstantExchangerInterface;
use coinmonkey\entities\Order;
use coinmonkey\entities\Amount;
use coinmonkey\entities\Coin;
use coinmonkey\entities\Order as OrderExchange;
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

    public function getExchangeStatus(OrderExchange $order) : ?integer
    {
        $address = $order->getAddress();
        $return = $this->tool->checkExchange($address->getExchangerOrderId());

        switch($return->status) {
            case 'new': return OrderExchange::STATUS_WAIT_YOUR_TRANSACTION;
            case 'processing': return OrderExchange::STATUS_EXCHANGER_PROCESSING;
            case 'processed': return OrderExchange::STATUS_DONE;
            default: return OrderExchange::STATUS_FAIL;
        }

        return null;
    }

    public function getEstimateAmount(Amount $amount, Coin $coin2) : Order
    {
        $give = $this->getCoinName($amount->getCoin()->getCode());
        $get = $this->getCoinName($coin2->getCode());
        if(!$give | !$get) {
            throw new \coinmonkey\exceptions\ErrorException('Market don\'t exists');
        }
        $limits = $this->tool->getLimits($give, $get);

        if($amount->getGivenAmount() < $limits->limits->min_amount | $amount->getGivenAmount() > $limits->limits->max_amount) {
            throw new \coinmonkey\exceptions\ErrorException('Minimum is ' . $limits->limits->min_amount . ' ' . $amount->getCoin()->getCode() . ' and maximum is ' . $limits->limits->max_amount . ' ' . $amount->getCoin()->getCode());
        }

        $rate = $this->tool->getRate($give, $get);

        $cost = round($amount->getGivenAmount()*$rate->rate, 8);

        return new Order(round($cost, 8, PHP_ROUND_HALF_UP), $coin2);
    }

    public function makeDepositAddress(string $clientAddress, Amount $amount, Coin $coin2) : array
    {
        $data = [
            'send' => $this->getCoinName($amount->getCoin()->getCode()),
            'receive' => $this->getCoinName($coin2->getCode()),
            'amount' => $amount->getGivenAmount(),
            'receiver_id' => $clientAddress,
        ];

        $res = $this->tool->makeExchange($data);

        return [
            'private' => null,
            'public' => null,
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
