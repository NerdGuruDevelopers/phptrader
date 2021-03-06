<?php 

if(!file_exists('config.inc.php'))
    exit('Error! set up config.inc.php first');
include_once('config.inc.php');
include_once('vendor/autoload.php');
use Coinbase\Wallet\Client;
use Coinbase\Wallet\Configuration;
use Coinbase\Wallet\Resource\Sell;
use Coinbase\Wallet\Resource\Buy;
use Coinbase\Wallet\Value\Money;
$t = new trader();

    $myname = $argv[0];
    switch($argv[1])
    {
        case 'buy':
            $amount = $argv[2];
            $sellat = $argv[3];
            $t->buyBTC($amount,$sellat);
        break;

        case 'sell':
            $amount = $argv[2];
            $t->sellBTC($amount,true);
        break;
        
        case 'order':
            $amount = $argv[2];
            $sellat = $argv[3];
            $buyat = $argv[4];

            $t->addBuyTransaction($amount,$buyat,$sellat);
        break;

        case 'watchdog':
            $t->watchdog();
        break;

        default:
            echo "Usage info\n---------------\n";
            echo "php $myname buy <amount in ".CURRENCY."> <sell when price increases by ".CURRENCY.">\n";
            echo "php $myname sell <amount in ".CURRENCY.">\n";
            echo "php $myname order <amount in ".CURRENCY."> <sell when price increases by ".CURRENCY."> <buy at btc price>\n";
            echo "\nExamples:\n---------------\n";
            echo "Buy 10 ".CURRENCY." in BTC and sell when it will be worth 12 ".CURRENCY.":\n  php $myname buy 10 2\n";
            echo "Sell 5 ".CURRENCY." of your BTC:\n  php $myname sell 5\n";
            echo "Add buy order for 15 ".CURRENCY." when 1 BTC is worth 1000 ".CURRENCY." and sell when the 15 ".CURRENCY." are worth 17 ".CURRENCY.":\n  php $myname order 15 2 1000\n";
        break;
    }

class trader
{
    public $buyPrice;
    public $sellPrice;
    public $spotPrice;
    public $lastSellPrice;
    
    private $client;
    private $account;
    private $walletID;
    private $transactions;
    private $traderID;


    function __construct()
    {
        $configuration = Configuration::apiKey(COINBASE_KEY, COINBASE_SECRET);
        $this->client = Client::create($configuration);
        $this->account = $this->client->getPrimaryAccount();
        $this->transactions = array();
        $this->traderID = substr(md5(time().microtime()."hello".rand(1,19999)),-3);

        if(file_exists('transactions.json'))
            $this->transactions = json_decode(file_get_contents('transactions.json'), true);

        $paymentMethods = $this->client->getPaymentMethods();

        //find ".CURRENCY." wallet ID
        foreach($paymentMethods as $pm)
        {
            if($pm->getName() == CURRENCY.' Wallet')
            {
                $this->walletID = $pm->getId();
                echo "[i] Found ".CURRENCY." Wallet ID: $this->walletID\n";
                break;
            }
        }
        if(!$this->walletID)
            exit("[ERR] Could not find your ".CURRENCY." Wallet. Do you have one on Coinbase?\n");

        $this->updatePrices();
    }

    function updatePrices()
    {
        $this->lastSellPrice = $this->sellPrice;
        $this->buyPrice =  floatval($this->client->getBuyPrice('BTC-'.CURRENCY)->getAmount());
        $this->sellPrice = floatval($this->client->getSellPrice('BTC-'.CURRENCY)->getAmount());
        $this->spotPrice = floatval($this->client->getSpotPrice('BTC-'.CURRENCY)->getAmount());

        if(!$this->lastSellPrice) $this->lastSellPrice = $this->sellPrice;

        if(DEV===true)
        {
            echo "[i] Buy price: $this->buyPrice\n";
            echo "[i] Sell price: $this->sellPrice\n";
            echo "[i] Spot price: $this->spotPrice\n";
            echo "[i] Difference buy/sell: ".($this->buyPrice-$this->sellPrice)."\n\n";
        }
        
    }

    function addBuyTransaction($eur,$buyat,$sellat)
    {
        echo "[i] Buying $eur € when price is <= $buyat ".CURRENCY."\n";
        $id = @max(array_keys($this->transactions))+1;
        $this->transactions[$id] = array('eur'=>$eur,'buyprice'=>$buyat,'sellat'=>$sellat);
        $this->saveTransactions();
        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Will buy *$eur ".CURRENCY."* when BTC price hits *$buyat ".CURRENCY."*. Currently it's at: *$this->sellPrice ".CURRENCY."*. Only *".($this->sellPrice-$buyat).' ".CURRENCY."* to go',':raised_hands:');
    }

    function buyBTC($amount,$sellat,$btc=false)
    {
        $eur = ($btc===true?($this->buyPrice*$amount):$amount);
        $btc = ($btc===true?$amount:($amount/$this->buyPrice));

        if(SIMULATE===false)
        {
            $buy = new Buy([
                'bitcoinAmount' => $btc,
                'paymentMethodId' => $this->walletID
            ]);
            
            $this->client->createAccountBuy($this->account, $buy);
        }
        $id = @max(array_keys($this->transactions))+1;
        $this->transactions[$id] = array('btc'=>$btc,'eur'=>$eur,'buyprice'=>$this->buyPrice,'sellat'=>$sellat);

        if(DEV===true)
            echo "[B #$id] Buying $eur €\t=\t$btc BTC\n";

        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Buying *$btc BTC* for *$eur ".CURRENCY."*",':moneybag:','Bot #'.$this->traderID);

        $this->saveTransactions();

        return $id;
    }

    function sellBTCID($id)
    {
        $data = $this->transactions[$id];
        unset($this->transactions[$id]);
        if(DEV===true)
             echo "[S #$id] Removed transaction #$id from list\n";
        $this->sellBTC($data['btc'],true);

        $profit = round(($data['btc']*$this->sellPrice)-($data['btc']*$data['buyprice']),2);

        if(ROCKETCHAT_REPORTING===true) sendToRocketchat("Selling *".$data['btc']." BTC* for *".$data['eur']." ".CURRENCY."*. Profit: *$profit ".CURRENCY."*",':money_with_wings:','Bot #'.$this->traderID);

        $this->saveTransactions();
    }

    function sellBTC($amount,$btc=false)
    {
        $eur = ($btc===true?($this->sellPrice*$amount):$amount);
        $btc = ($btc===true?$amount:($amount/$this->sellPrice));

        $sell = new Sell([
            'bitcoinAmount' => $btc 
        ]);
        if(DEV===true)
            echo "[S] Selling $eur € =\t$btc BTC\n";
        if(SIMULATE===false)
            $this->client->createAccountSell($this->account, $sell);            
        
    }

    function watchdog()
    {
        if(count($this->transactions)<=0)
        {
            echo "[ERR] No transactions to watch\n";
            return;
        }
            
        while(1)
        {
            $this->updatePrices();

            if($this->lastSellPrice!=$this->sellPrice && round(abs($this->sellPrice-$this->lastSellPrice),2) > 0)
            {
                echo "[BTC] Price went ".($this->sellPrice>$this->lastSellPrice?'up':'down')." by ".round($this->sellPrice-$this->lastSellPrice,2)." ".CURRENCY."\n";
                //if(ROCKETCHAT_REPORTING===true)
                //    sendToRocketchat("Sell price changed by *".round(($this->sellPrice-$this->lastSellPrice),2)." ".CURRENCY."* Was: $this->lastSellPrice, is now: $this->sellPrice",':information_source:');
            }
                

            foreach($this->transactions as $id=>$td)
            {
                $btc = $td['btc'];
                $eur = $td['eur'];
                $buyprice = $td['buyprice'];
                $sellat = $td['sellat']+$eur;
                $newprice = $btc*$this->sellPrice;
                
                $diff = round(($this->sellPrice-$buyprice)*$btc,2);

                //if this is a future transaction
                if(!$btc)
                {
                    if($this->buyPrice <= $buyprice) //time to buy?
                    {
                        unset($this->transactions[$id]);
                        $this->buyBTC($eur, ($sellat-$eur) );
                    }
                        
                }
                else
                {
                    $untilsell = round(($this->sellPrice-$sellat)*$btc,2);
                    $message = " [#$id] Holding \t$eur ".CURRENCY." at buy. Now worth:\t ".round($newprice,2)." ".CURRENCY.". Change: ".($diff)." ".CURRENCY.". Will sell at \t$sellat ".CURRENCY." (+$untilsell) ".CURRENCY."\n";
                    echo $message;

                    if( ($this->sellPrice*$btc) >= $sellat )
                    {
                        echo "  [#$id] AWWYEAH time to sell $btc BTC since it hit ".($this->sellPrice*$btc)." ".CURRENCY.". Bought at $eur ".CURRENCY."\n";
                        $this->sellBTCID($id);
                    }
                        
                }

                

                
            }
            

            sleep(10);
            echo "------\n";
        }
    }

    function saveTransactions()
    {
        file_put_contents("transactions.json",json_encode($this->transactions));
    }

}


//rocketchat
function sendToRocketchat($message,$icon=':ghost:',$username='Traderbot')
{
  $data = array("icon_emoji"=>$icon,
                "username"=>$username,
		        "text"=>$message);
  makeRequest(ROCKETCHAT_WEBHOOK,array('payload'=>json_encode($data)));
}

function makeRequest($url,$data,$headers=false,$post=true)
{
    $headers[] = 'Content-type: application/x-www-form-urlencoded';
    $options = array(
        'http' => array(
            'header'  => $headers,
            'method'  => $post?'POST':'GET',
            'content' => http_build_query($data)
        )
    );
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) { /* Handle error */ }
    return json_decode($result,true);
}