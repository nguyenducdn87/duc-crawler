<?php

namespace DucCrawler;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use DucCrawler\Exceptions\AmazonServerException;
use DucCrawler\Exceptions\CurrencyConversionException;
use DucCrawler\Exceptions\CurrencyRatesDownloadException;
use Symfony\Component\DomCrawler\Crawler;

class AmazonScraper
{
    private $limitedStockString = [
        'it' => ['Solo ([0-9]*) con disponibilità immediata', 'Disponibilità: solo ([0-9]*)'],
        'de' => ['Nur noch ([0-9]*) Stück auf Lager - jetzt bestellen.', 'Nur noch ([0-9]*) auf Lager', 'Only ([0-9]*) left in stock\.'],
        'fr' => ['Plus que ([0-9]*) ex. Commandez vite !', "Il ne reste plus que ([0-9]*)"],
        'es' => ['Sólo hay ([0-9]*) en stock. Cómpralo cuanto antes\.', 'Sólo\squeda\(n\)\s([0-9]*)', 'Sólo queda(n) ([0-9]*) en stock \(hay más unidades en camino\)\.'],
        'co.uk' => ['Only ([0-9]*) left in stock\.'],
    ];
    private $unavailableStrings = [
        "it" => ["Attualmente non disponibile","Non Disponibile"],
        "de" => ["Nicht auf Lager","Dieser Artikel ist noch nicht verfügbar","Derzeit nicht auf Lager", 'Not in stock', 'Temporarily out of stock'],
        'fr' => ['Pas de stock', 'Temporairement en rupture de stock'],
        'es' => ['Temporalmente sin stock'],
        'co.uk' => ['Not in stock', 'Temporarily out of stock']
    ];
    private $olp = [
        'it' => [
            'new' => ["Nuovi: ([0-9]+) venditori da (EUR [0-9\.,]+)", "Nuovo: ([0-9]+) venditore da (EUR [0-9\.,]+)"],
            'used' => ["Usati: ([0-9]+) venditori da (EUR [0-9\.,]+)"],
            'renewed' => ["([0-9]+) Renewed da (EUR [0-9\.,]+)"],
        ],
        'de' => [
            'new' => ["([0-9]+) neu ab (EUR [0-9\.,]+)", '([0-9]+) new from (EUR [0-9\.,]+)'],
            'used' => ["([0-9]+) gebraucht ab (EUR [0-9\.,]+)", "([0-9]+) used from (EUR [0-9\.,]+)"],
            'refurbished' => ["([0-9]+) B-Ware & 2. Wahl ab (EUR [0-9\.,]+)", "([0-9]+) Zertifiziert und Generalüberholt ab (EUR [0-9\.,]+)"]
        ],
        'fr' => [
            'new' => ["([0-9]+) neuf à partir de (EUR [0-9\.,]+)", "([0-9]+) neufs à partir de (EUR [0-9\.,]+)"],
            'used' => ["([0-9]+) d'occasion à partir de (EUR [0-9\.,]+)"],
            'refurbished' => ["([0-9]+) reconditionné(s) à partir de  (EUR [0-9\.,]+)"],
        ],
        'es' => [
            'new' => ["Nuevos: ([0-9]+) desde (EUR [0-9\.,]+)"],
            'used' => ["De 2ª mano: ([0-9]+) desde (EUR [0-9\.,]+)"],
            'refurbished' => [],
        ],
        'co.uk' => [
            'new' => ['([0-9]+) new from (£[0-9\.,]+)'],
            'used' => ["([0-9]+) used from (£[0-9\.,]+)"],
            'refurbished' => [],
        ]
    ];
//    private $disponibileDaQuestiFornitori = [
//        'it' => 'Disponibile presso questi venditori.',
//        'de' => 'Erhältlich bei diesen Anbietern',
//        'fr' => 'Voir les offres de ces vendeurs.',
//        'es' => 'Disponible a través de estos vendedores.',
//        'co.uk' => 'Available from these sellers.',
//    ];
//    private $arrivaTraText = [
//        'it' => 'Arriva tra',
//        'de' => 'Ankunft zwischen',
//        'fr' => 'Arrive entre',
//        'es' => 'Llega entre el'
//    ];
    private $conditions = [
        'it' => 'Nuovo',
        'de' => 'Neu',
        'fr' => 'Neuf',
        'es' => 'Nuevo',
        'co.uk' => 'New',
    ];

    private $amazonMerchants = [
        "Amazon.it" => "A11IL2PNWYJU7H",
        "Amazon.de" => "A3JWKAKR8XB7XF",
        "Amazon.fr" => "A1X6FK5RDHNB96",
        "Amazon.es" => "A1AT7YVPFBWXBL",
        "Amazon.co.uk" => "A3P5ROKL5A1OLE",

    ];

//    private $countryCodes = [
//        'it' => 'IT',
//        'de' => 'DE',
//        'fr' => 'FR',
//        'es' => 'ES',
//        'co.uk' => 'GB',
//    ];
    private $proxies = [];
    private $proxy;

    /** @var Client */
    private $client;

    /** @var CookieJar */
    private $cookieJar;
    private $timeout = 10;
    private $max_tries = 100;
    private $output;
    private $currencies = [
        "it" => "EUR",
        "de" => "EUR",
        "fr" => "EUR",
        "es" => "EUR",
        "co.uk" => "GBP",
    ];
    private $lastPageBody;
    private $lastRequestStatusCode;
    private $silentMode = true;
    private $perProxyMaxTries = 10;

    /** @var ScraperOutputInterface */
    private $outputInterface;

    /**
     * AmazonScraper constructor.
     * @param ScraperOutputInterface|callable $output
     * @param bool $silentMode
     */
    public function __construct($output = null, $silentMode = true)
    {
        $this->setInterface(new class implements ScraperOutputInterface {
            public function beforeProxyChange(AmazonScraper $scraper) : bool{ return true;}
            public function afterProxyChange(AmazonScraper $scraper, string $new_proxy = null){}
            public function error(AmazonScraper $scraper, \Throwable $error){}
            public function message($message, int $verbosity = 32){}
            public function requestError(AmazonScraper $scraper, Request $request){}
            public function clientCreating(AmazonScraper $scraper, array $config): array
            {
                return $config;
            }
            public function clientCreated(AmazonScraper $scraper, Client $client): Client
            {
                return $client;
            }
        });

        if($output) {
            if(is_callable($output))
                $this->setOutput($output);
            elseif($output instanceof ScraperOutputInterface)
                $this->setInterface($output);
        }

        $this->silentMode = $silentMode;
        $this->createClient();
    }

    public static function create(ScraperOutputInterface $scraperOutput)
    {
        $class = new static($scraperOutput);
        return $class;
    }

    public function setCookies(array $cookies)
    {
        $cookieJar = new CookieJar(false, $cookies);
        $this->createClient($cookieJar);
        return $this;

    }

    public function clearCookies($domain = null)
    {
        $this->cookieJar->clear($domain);
        return $this;
    }

    public function getCookies()
    {
        return $this->cookieJar->toArray();
    }

    public function getCookieJar()
    {
        return $this->cookieJar;
    }

    private function createClient($cookieJar = null)
    {
        $this->cookieJar = $cookieJar ?: new CookieJar();

        $config = [
            'cookies' => $this->cookieJar,
            'verify' => false,
            'http_errors' => false,
            'headers' => [
                'Accept-Encoding' => 'gzip, deflate, br',
                //"User-Agent" => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36",
                "User-Agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36",
                "Accept" => "*/*"
            ]
        ];

        $config = $this->outputInterface->clientCreating($this, $config);
        $client = new Client($config);

        $this->client = $this->outputInterface->clientCreated($this, $client);

        $this->debug("New Guzzle Client Created");

        return $this;
    }

    public function setProxies(array $proxyList)
    {
        $this->proxies = $proxyList;
        return $this;
    }

    public function getProxies()
    {
        return $this->proxies;
    }

    public function getProxy()
    {
        return $this->proxy;
    }
    public function setProxy($proxy)
    {
        $this->proxy = $proxy;
        $this->outputInterface->afterProxyChange($this, $proxy);
        $this->debug("Changed Proxy: ". $this->proxy);
    }

    public function forceProxyRenew($no_proxy = false)
    {
        $this->debug("Force Proxy Renew...");
        if($no_proxy) {
            $this->proxy = null;
        } else
            $this->renewProxy();
    }

    public function getLastPageBody()
    {
        return $this->lastPageBody;
    }

    public function getLastRequestStatusCode()
    {
        return $this->lastRequestStatusCode;
    }

    private function renewProxy()
    {
        $proxy = array_shift($this->proxies);
        if($this->outputInterface->beforeProxyChange($this) && $proxy && $proxy != $this->proxy) {
            $this->setProxy($proxy);
        }
        return $this->proxy;
    }

    private function debug($message)
    {
        if(is_callable($this->output))
            call_user_func($this->output, $message, 64);
        elseif($this->outputInterface)
            $this->outputInterface->message($message, 64);
        elseif(!$this->silentMode)
            printf("%s - %s\n", date('d/m/Y H:i:s'), $message);

        return $this;
    }

    /**
     * @param Request $request
     * @param null $timeout
     * @param null $max_tries
     * @param array $options
     * @return string
     * @throws AmazonServerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getPage($request, $timeout = null, $max_tries = null, $options = [])
    {
        $this->prependProxy();
        if(!$timeout) $timeout = $this->timeout;
        if(!$max_tries) $max_tries = $this->max_tries;
        $tries  = 0;
        $proxyTries = 0;
        do {
            try {
                $tries++;
                $status = null;

                $proxy = $this->renewProxy();

                $this->debug("[i] Proxy: $proxy");

                $options['headers']['User-Agent'] = $this->getRandomUserAgent();

                $response = $this->client->send($request, array_merge(compact('proxy', 'timeout'), $options));
                $status = $response->getStatusCode();
                $body = $response->getBody()->getContents();
                $this->lastRequestStatusCode = $status;
                switch ($status) {
                    case 200:

                        if (mb_strpos($body, "captchacharacters") !== false) {

                            $proxyTries++;

                            $this->debug(sprintf("%s - Errore Captcha, riprovo...", $tries));
                            $status = 500;

                            $tries--;
                            if($proxyTries < $this->perProxyMaxTries)
                                $this->prependProxy();
                            else
                                $proxyTries = 0;

                            continue;

                        } elseif(!$body) {
                            $this->debug(sprintf("%s - Pagina Vuota...", $tries));
                            $status = 500;
                            $this->lastRequestStatusCode = 500;
                            continue;
                        } elseif(mb_stripos($body, "/gp/errors/404.html") !== false) {
                            $this->debug(sprintf("%s - Pagina Vuota...", $tries));
                            $status = 404;
                            $this->lastRequestStatusCode = 404;
                            break 2;

                        } elseif(mb_strpos($body, 'signinSSOButtons') !== false || mb_strpos($body, 'Lightspeed System - Web Access') !== false) {
                            $this->debug(sprintf("%s - Pagina Login del Proxy...", $tries));
                            $status = 500;
                            continue ;
                        }
                        break;

                    case 404:

                        $body = "ref=cs_404_link";
                        break 2;

                    case 503:
                        $proxyTries++;
                        $this->debug(sprintf("%s/%s - Errore 503", $tries, $proxyTries));
                        $this->outputInterface->requestError($this, $request);
                        $tries--;
                        if($proxyTries < $this->perProxyMaxTries)
                            $this->prependProxy();
                        else
                            $proxyTries = 0;
                        continue;

                    default:
                        $this->debug(sprintf("%s - Status Code: $status", $tries));
                        $this->outputInterface->requestError($this, $request);
                        continue;
                }

            } catch(RequestException $requestException) {
                if($requestException->hasResponse()) {
                    $status = $requestException->getResponse()->getStatusCode();

                    if($status == 404) {
                        $body = "";
                        break;
                    }

                    $this->debug(sprintf("%s - Errore " . $status . ":" .$requestException->getMessage().", riprovo...", $tries));
                } else {
                    $this->debug(sprintf("%s - Errore: " . $requestException->getMessage() . ", riprovo...", $tries));
                }
                $this->outputInterface->requestError($this, $request);
                $this->outputInterface->error($this, $requestException);

                continue;
            }


        } while($status !== 200 && (!$max_tries || $max_tries > $tries));

        if($max_tries && $tries >= $max_tries && $status !== 200) {
            $this->outputInterface->requestError($this, $request);
            throw new AmazonServerException($response, "Reached Max Tries With Errors! (" . $max_tries . ")");
        }

        $this->lastPageBody = $body;

        return $body;
    }

    public function prependProxy()
    {
        array_unshift($this->proxies, $this->proxy);
    }

    /**
     * @param $url
     * @param $timeout
     * @param null $max_tries
     * @return mixed
     * @throws AmazonServerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getAmazonPage($url, $timeout = null, $max_tries = null)
    {
        $request = new Request("get", $url, []);

        return $this->getPage($request, $timeout, $max_tries);
    }

    /**
     * @param $marketplace
     * @param string $countryCode
     * @param array $options
     * @return string|array
     * @throws AmazonServerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function changeAddress($marketplace, $countryCode, $options = [])
    {
        $domain = "https://www.amazon.".  $marketplace;
        $request = new Request("post", $domain. "/gp/delivery/ajax/address-change.html", [
                'Accept' => 'application/json',//'text/html,*/*',
                'Accept-Encoding' => "gzip, deflate, br",
                'X-Requested-With' => 'XMLHttpRequest',
                'Origin' => $domain,
                'Referer' => $domain . "/ref=nav_logo",
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                'User-Agent' => $this->getRandomUserAgent()//'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/65.0.3325.181 Safari/537.36'
            ]);

        $rawBody = $this->getPage($request, null, null, array_merge($options, [
            'form_params' => [
                'locationType' => 'COUNTRY',
                'district' => $countryCode,
                'countryCode' => $countryCode,
                'deviceType' => 'web',
                'pageType' => 'Gateway',
                'actionSource' => 'glow'
            ]
        ]));

        $body = json_decode($rawBody, true);

        return new AmazonChangeAddressResponse($body);

    }

    /**
     * @param string $asin
     * @param string $market
     * @param string $condition
     * @return string
     */
    public function getOfferListingsPageUrl($asin, $market, $condition = "new")
    {
        return "https://www.amazon.$market/gp/offer-listing/$asin/ref=dp_olp_0?ie=UTF8&condition=$condition";
    }

    /**
     * @param string $asin
     * @param string $market
     * @param string|null $sellerId
     * @return string
     */
    public function getProductPageUrl($asin, $market, $sellerId = null)
    {
        $url = "https://www.amazon.$market/dp/$asin?psc=1";
        if($sellerId) $url.="&m=$sellerId";

        return $url;
    }

    /**
     * @param string $html
     * @param string $market
     * @param null $url
     * @return array
     */
    public function extractPrices($html, $market, $url = null)
    {
        $dom = new Crawler(null, $url);
        $dom->addHtmlContent($html);

        $prices = [];

        $destination = $this->getValue($dom, "#glow-ingress-block #glow-ingress-line2");
        if($destination) {
            $this->debug("Market: " .$market . " - Destination Address Detected: " . $destination);
        }

//        if(!$dom->filter('#olpOfferList')->count() && $this->getLastRequestStatusCode() != 404) {
//
//            dump($this->getLastRequestStatusCode(), $this->getLastPageBody());
//
//            throw new \Exception("Invalid Page!");
//        }

        $dom->filter('#olpOfferList div.olpOffer')->each(function (Crawler $node) use (&$prices, $market, $html) {

            $price = $this->clean_price($this->getValue($node, ".olpOfferPrice", 'text'));
            $shipping = $this->clean_price($this->getValue($node, ".olpShippingInfo .olpShippingPrice", 'text') ?: 0);
            $total = $price + $shipping;
            $condition = $this->clean_text($this->getValue($node, ".olpCondition", 'text'));
            $sellerName = $this->getValue($node, ".olpSellerName a", 'html');
            $sellerUrl = $this->getValue($node, ".olpSellerName a", 'href');
            if ($sellerName) {
                parse_str($sellerUrl, $qstring);
                $isAmazonFulFilled = $qstring['isAmazonFulfilled'] ?? false;
                $soldByAmazon = false;
                $sellerId = $qstring['seller'] ?? null;
            } else {
                //venduto da amazon
                $sellerName = $this->getValue($node, '.olpSellerName img', 'alt');
                $sellerId = $this->amazonMerchants[$sellerName] ?? null;
                $soldByAmazon = true;
                $isAmazonFulFilled = true;
            }

            $fastTrack = [];
            $node->filter('ul.olpFastTrack > li:not(:last-child) > span.a-list-item')->each(function(Crawler $node) use(&$fastTrack) {
                $fastTrack[] = $this->clean_text($node->html());
            });
            $needs_verification = mb_stripos($html, "action=\"/gp/prime/handle-buy-box.html\"") !== false;

            $currency = $this->currencies[$market] ?? "EUR";

            if($currency !== 'EUR') {

                $price = round($this->convert_currency($price, $currency), 2);
                $shipping = $shipping ? round($this->convert_currency($shipping, $currency),2) : 0;
                $total = round($price + $shipping, 2);

            }

            if(!$condition || $condition == $this->conditions[strtolower($market)] ?? 'Nuovo')
                $prices[] = compact('price', 'shipping', 'total', 'condition', 'sellerName', 'sellerId', 'isAmazonFulFilled', 'fastTrack', 'handlingTimes', 'soldByAmazon', 'needs_verification');
        });

        return $prices;
    }

    /**
     * @param string|Crawler $dom
     * @param $url
     * @param string $market
     * @return array
     */
    public function extractProductData($dom, $url, $market)
    {

        if(is_string($dom)) $dom = new Crawler($dom, $url);


        $fields = [
            [
                'name' => 'name',
                'selector' => "#productTitle",
                'attribute' => "_text",
                'callback' => function ($value) {
                    return $this->clean_text($value);
                }
            ],
            [
                'name' => 'brand',
                'selector' => "#bylineInfo",
                'attribute' => "_text",
                'callback' => function ($value) {
                    return $this->clean_text($value);
                }
            ],
            [
                'name' => 'image',
                'selector' => "[data-a-dynamic-image]",
                'attribute' => "data-a-dynamic-image",
                'callback' => function ($value) {

                    $images = json_decode($value, true);
                    if($images && is_array($images)) {
                        return array_keys($images)[0];
                    }

                    return null;

                }
            ],
            [
                'name' => 'destination',
                'selector' => "#glow-ingress-block #glow-ingress-line2",
                'attribute' => "_text"
            ],
            [
                'name' => 'price',
                'selector' => '#priceblock_ourprice, .price3P',
                'attribute' => '_text', //our_price
                'callback' => function ($value) {
                    return $this->clean_price($value);
                }
            ],
            [
                'name' => 'dealprice',
                'selector' => '#priceblock_dealprice',
                'attribute' => '_text', //deal_price
                'callback' => function ($value) {
                    return $this->clean_price($value);
                }
            ],
            [
                'name' => 'saleprice',
                'selector' => '#priceblock_saleprice',
                'attribute' => '_text', //sale_price
                'callback' => function ($value) {
                    return $this->clean_price($value);
                }
            ],
            [
                'name' => 'shipping', 'selector' => '#ourprice_shippingmessage, .shipping3P', 'attribute' => '_text', //our_price_shipping_message
                'callback' => function ($value) {
                    return $this->clean_price($value);
                }],
            [
                'name' => 'dealshipping', 'selector' => '#dealprice_shippingmessage', 'attribute' => '_text', //deal_price_shipping_message
                'callback' => function ($value) {
                    return $this->clean_price($value);
                }],
            [
                'name' => 'saleshipping',
                'selector' => '#saleprice_shippingmessage',
                'attribute' => '_text', //sale_price_shipping_message
                'callback' => function ($value) {
                    return $this->clean_price($value);
                }
            ],
            [
                'name' => 'merchant', 'selector' => '#merchant-info', 'attribute' => '_text',
                'callback' => function ($value) {
                    return $this->clean_text($value);
                }],
            [
                'name' => 'merchant_url', 'selector' => '#merchant-info a', 'attribute' => 'href',
                'callback' => function ($value) {
                    return $this->clean_text($value);
                }],
            ['name' => 'olp', 'selector' => '[data-feature-name=olp]', 'attribute' => 'html',
                'callback' => function ($value) use($market) {

                    $data = new Crawler($value);

                    return $this->parseOlp($data->filter('span.olp-padding-right')->extract(['_text']), $market);


                }],
            ['name' => 'availability', 'selector' => '#availability', 'attribute' => '_text',
                'callback' => function ($value) {return $this->clean_text($value);}],
            ['name' => 'holiday_delivery_message', 'selector' => '[data-feature-name=holidayDeliveryMessage]', 'attribute' => '_text',
                'callback' => function ($value) {
                    return $this->clean_text($value);
                }],
            ['name' => 'dynamic_delivery_message', 'selector' => '#ddmDeliveryMessage', 'attribute' => '_text',
                'callback' => function ($value) {
                    return $this->clean_text($value);
                }],
            ['name' => 'weight', 'selector' => '.shipping-weight .value, #productDetailsTable li:contains("Peso"), #detail_bullets_id li:contains("Peso")', 'attribute' => '_text',
                'callback' => function ($value) {

                    $value = str_ireplace(['Peso articolo:', 'Peso di spedizione:'], '', $value);

                    return $this->clean_text($value);
                }],
        ];

        $data = [];
        foreach ($fields as $field) {

            $value = $this->getValue($dom, $field['selector'], $field['attribute']);
            if (isset($field['callback']))
                $value = $field['callback']($value, $data);

            $data[$field['name']] = $value;

        }

        $data['currency'] = $this->currencies[$market];

        if($data['currency'] !== 'EUR') {
            $data['price'] = round($this->convert_currency($data['price'], $data['currency']), 2);
            $data['shipping'] = $data['shipping'] ? round($this->convert_currency($data['shipping'], $data['currency']),2) : 0;
        }

        $availability = $data['availability'] ?? null;
        $qty = $this->parseQty($availability, $market);
        $unavailable = false;

        $unavailableStrings = $this->unavailableStrings[$market] ?? [];
        foreach($unavailableStrings as $regex) {
            if(preg_match("/$regex/", $availability)) {
                $unavailable = true;
                break;
            }
        }



        if($unavailable) {
            $qty = 0;
        } else {
            $limitedStockStrings = $this->limitedStockString[$market] ?? [];

            foreach($limitedStockStrings as $regex) {

                if(preg_match("/$regex/", $availability, $matches)) {
                    $qty = $matches[1];
                    break;
                }

            }
        }

        $data['qty'] = $qty;

        return $data;
    }

    public function parseQty(String $availability, $market)
    {
        $qty = 21;
        $unavailable = false;

        $unavailableStrings = $this->unavailableStrings[$market] ?? [];
        foreach($unavailableStrings as $regex) {
            if(preg_match("/$regex/", $availability)) {
                $unavailable = true;
                break;
            }
        }

        if($unavailable) {
            $qty = 0;
        } else {
            $limitedStockStrings = $this->limitedStockString[$market] ?? [];

            list($matches) = $this->preg_multi($limitedStockStrings, $availability);
            if($matches) {
                $qty = $matches[1];
            }
        }

        return $qty;
    }

    /**
     * @param array $olp
     * @param string $market
     * @return array
     */
    public function parseOlp($olp, $market)
    {
        $templates = $this->olp[$market] ?? [];


        $results = [];
        foreach($olp as $value) {
            foreach ($templates as $condition => $regexes) {

                foreach ($regexes as $regex) {

                    if (preg_match("/$regex/", $value, $data)) {

                        $results[$condition] = [
                            'qty' => (int)$data[1],
                            'amount' => $this->clean_price($data[2])
                        ];
                        break;
                    }


                }


            }
        }

        return $results;

    }

    public function setTimeout($int = null)
    {
        $this->timeout = $int;
    }

    public function setMaxTries($int = null)
    {
        $this->max_tries = $int;
    }

    function getValue(Crawler $dom, $filter, $attr = 'html', $default = null)
    {
        $node = $dom->filter($filter);
        if($node->count()) {
            switch($attr) {
                case "html":
                    return $node->html();

                case "_text":
                case "text":
                    return $node->text();

                default:
                    return $node->attr($attr);
            }
        }
        return $default;
    }

    public function setOutput(callable $callback)
    {
        $this->output = $callback;
    }

    /**
     * @param $asin
     * @param $marketplace
     * @return array
     * @throws AmazonServerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getOffers($asin, $marketplace, $headers = [])
    {
        $url = $this->getOfferListingsPageUrl($asin, $marketplace);

        $data = $this->getPage(new Request("get", $url, $headers));
        $offers = $this->extractPrices($data, $marketplace, $url);

        return $offers;
    }

    /**
     * @param $asin
     * @param $marketplace
     * @param null $sellerId
     * @return array
     * @throws AmazonServerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProductData($asin, $marketplace, $sellerId = null)
    {
        $url = $this->getProductPageUrl($asin, $marketplace, $sellerId);
        $html = $this->getAmazonPage($url);

        $data = $this->extractProductData($html, $url, $marketplace);

        return $data;
    }

    private function clean_price($price)
    {
        $price = preg_replace("/[^0-9.,]/", "", $price);

        $point = strpos($price, '.');
        $comma = strpos($price, ',');

        //ci sono tutti e due
        if ($point !== false && $comma !== false) {

            //prendo il primo come separatore migliaia;

            $price = str_replace($comma > $point ? '.' : ',', '', $price);

        }

        $price = floatval(str_replace([',', '.'], '.', $price));

        return $price;
    }

    private function clean_text($text)
    {
        return trim(preg_replace('/\s+/', " ", $text));
    }

    /**
     * @param $from
     * @param $to
     * @return mixed
     * @throws CurrencyRatesDownloadException
     */
    private function get_currency_rates($from, $to)
    {
        $response = (new Client())->get("https://api.exchangeratesapi.io/latest?base=$from&symbols=$to");
        $rates = json_decode($response->getBody(), true);
        if($rates && isset($rates['rates'])) {
            return $rates['rates'][$to];

        }

        throw new CurrencyRatesDownloadException();
    }

    /**
     * @param $amount
     * @param $from
     * @param string $to
     * @return mixed
     * @throws CurrencyConversionException
     * @throws CurrencyRatesDownloadException
     */
    private function convert_currency($amount, $from, $to = 'EUR')
    {
        $rate = $this->get_currency_rates($from, $to);

        if ($rate) {

            $amount *= $rate;

            return $amount;
        }

        throw new CurrencyConversionException();

    }

    private function preg_multi($re, $haystack, $utf8 = true)
    {
        if(!is_array($re)) $re = [$re];
        $re = array_map(function($regex) {

            if($regex[0] != "/")
                return "/$regex/u";

            return $regex;
        }, $re);

        if(!$utf8)
            $haystack = iconv('UTF-8', 'ASCII//TRANSLIT', $haystack);


        foreach ($re as $index => $format) {

            if (preg_match($format, $haystack, $data) ) {

                return [$data, $index];

            }
        }


        return [[], false];

    }

    public function setInterface(ScraperOutputInterface $scraperOutput)
    {
        $this->outputInterface = $scraperOutput;
    }

    /**
     * @return int
     */
    public function getPerProxyMaxTries(): int
    {
        return $this->perProxyMaxTries;
    }

    /**
     * @param int $perProxyMaxTries
     */
    public function setPerProxyMaxTries(int $perProxyMaxTries)
    {
        $this->perProxyMaxTries = $perProxyMaxTries;
    }

    private function getRandomUserAgent()
    {
        $agents = [

            "Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)",
            "Opera/9.80 (Windows NT 6.2; Win64; x64) Presto/2.12 Version/12.16",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0)",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; WOW64; Trident/6.0)",
            "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; .NET4.0C; .NET4.0E)",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.95 Safari/537.11",
            "Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.3; WOW64; Trident/7.0; rv:11.0) like Gecko",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.143 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/31.0.1650.63 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/35.0.1916.153 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.120 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.95 Safari/537.11",
            "Mozilla/5.0 (Windows NT 6.1; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/6.0)",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.31 (KHTML like Gecko) Chrome/26.0.1410.64 Safari/537.31",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.125 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.143 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/30.0.1599.101 Safari/537.36",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.0; Trident/5.0)",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/27.0.1453.110 Safari/537.36",
            "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0)",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.103 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.120 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:26.0) Gecko/20100101 Firefox/26.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/39.0.2171.95 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.64 Safari/537.11",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/31.0.1650.63 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; WOW64; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:30.0) Gecko/20100101 Firefox/30.0",
            "Mozilla/5.0 (Windows NT 6.3; WOW64; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/31.0.1650.63 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.143 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:27.0) Gecko/20100101 Firefox/27.0",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; WOW64; Trident/6.0)",
            "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/33.0.1750.154 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/38.0.2125.111 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:25.0) Gecko/20100101 Firefox/25.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.103 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:29.0) Gecko/20100101 Firefox/29.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/35.0.1916.114 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 1094) AppleWebKit/537.77.4 (KHTML like Gecko) Version/7.0.5 Safari/537.77.4",
            "Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.120 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/35.0.1916.153 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 1094) AppleWebKit/537.78.2 (KHTML like Gecko) Version/7.0.6 Safari/537.78.2",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.1 (KHTML like Gecko) Chrome/21.0.1180.89 Safari/537.1",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:24.0) Gecko/20100101 Firefox/24.0",
            "Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.143 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:37.0) Gecko/20100101 Firefox/37.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/31.0.1650.57 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:28.0) Gecko/20100101 Firefox/28.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:33.0) Gecko/20100101 Firefox/33.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.124 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.103 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:16.0) Gecko/20100101 Firefox/16.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23.0",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 712 like Mac OS X) AppleWebKit/537.51.2 (KHTML like Gecko) Version/7.0 Mobile/11D257 Safari/9537.53",
            "Mozilla/5.0 (iPad; CPU OS 613 like Mac OS X) AppleWebKit/536.26 (KHTML like Gecko) Version/6.0 Mobile/10B329 Safari/8536.25",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.9; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.31 (KHTML like Gecko) Chrome/26.0.1410.64 Safari/537.31",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.31 (KHTML like Gecko) Chrome/26.0.1410.64 Safari/537.31",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.9; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 613 like Mac OS X) AppleWebKit/536.26 (KHTML like Gecko) Version/6.0 Mobile/10B329 Safari/8536.25",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 1094) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.94 Safari/537.36",
            "Mozilla/5.0 (X11; Ubuntu; Linux x8664; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:22.0) Gecko/20100101 Firefox/22.0",
            "Mozilla/5.0 (iPad; CPU OS 511 like Mac OS X) AppleWebKit/534.46 (KHTML like Gecko) Version/5.1 Mobile/9B206 Safari/7534.48.3",
            "Mozilla/5.0 (X11; Ubuntu; Linux x8664; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 1094) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.143 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.125 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 1094) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.120 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/32.0.1700.107 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:35.0) Gecko/20100101 Firefox/35.0",
            "Mozilla/5.0 (X11; Linux x8664) AppleWebKit/537.36 (KHTML like Gecko) Chrome/36.0.1985.143 Safari/537.36",
            "Mozilla/5.0 (X11; Linux x8664) AppleWebKit/537.36 (KHTML like Gecko) Chrome/37.0.2062.94 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:38.0) Gecko/20100101 Firefox/38.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/28.0.1500.95 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:26.0) Gecko/20100101 Firefox/26.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 1010) AppleWebKit/600.1.8 (KHTML like Gecko) Version/8.0 Safari/600.1.8",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.95 Safari/537.11",
            "Mozilla/5.0 (Windows NT 5.1; rv:12.0) Gecko/20100101 Firefox/12.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/34.0.1847.116 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/30.0.1599.101 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.97 Safari/537.11",
            "Mozilla/5.0 (Windows NT 5.1; rv:27.0) Gecko/20100101 Firefox/27.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/39.0.2171.95 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/27.0.1453.116 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:26.0) Gecko/20100101 Firefox/26.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:19.0) Gecko/20100101 Firefox/19.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:22.0) Gecko/20100101 Firefox/22.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:20.0) Gecko/20100101 Firefox/20.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:34.0) Gecko/20100101 Firefox/34.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:19.0) Gecko/20100101 Firefox/19.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:25.0) Gecko/20100101 Firefox/25.0",
            "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0; .NET CLR 2.0.50727; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729)",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.64 Safari/537.11",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/30.0.1599.101 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:12.0) Gecko/20100101 Firefox/12.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/34.0.1847.131 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/28.0.1500.72 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/33.0.1750.154 Safari/537.36",
            "Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; Trident/4.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; InfoPath.2)",
            "Mozilla/5.0 (Windows NT 6.1; rv:30.0) Gecko/20100101 Firefox/30.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:24.0) Gecko/20100101 Firefox/24.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:23.0) Gecko/20100101 Firefox/23.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:27.0) Gecko/20100101 Firefox/27.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/35.0.1916.114 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/29.0.1547.66 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; rv:24.0) Gecko/20100101 Firefox/24.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:36.0) Gecko/20100101 Firefox/36.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:20.0) Gecko/20100101 Firefox/20.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:25.0) Gecko/20100101 Firefox/25.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/29.0.1547.76 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; rv:29.0) Gecko/20100101 Firefox/29.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:13.0) Gecko/20100101 Firefox/13.0.1",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:16.0) Gecko/20100101 Firefox/16.0",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.4 (KHTML like Gecko) Chrome/22.0.1229.94 Safari/537.4",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0)",
            "Mozilla/5.0 (Windows NT 5.1; rv:30.0) Gecko/20100101 Firefox/30.0",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 704 like Mac OS X) AppleWebKit/537.51.1 (KHTML like Gecko) Version/7.0 Mobile/11B554a Safari/9537.53",
            "Mozilla/5.0 (Windows NT 6.1; rv:16.0) Gecko/20100101 Firefox/16.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML like Gecko) Chrome/23.0.1271.97 Safari/537.11",
            "Mozilla/5.0 (Windows NT 5.1; rv:17.0) Gecko/20100101 Firefox/17.0",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/35.0.1916.153 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:29.0) Gecko/20100101 Firefox/29.0",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 711 like Mac OS X) AppleWebKit/537.51.2 (KHTML like Gecko) Version/7.0 Mobile/11D201 Safari/9537.53",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/30.0.1599.69 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/29.0.1547.76 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.22 (KHTML like Gecko) Chrome/25.0.1364.172 Safari/537.22",
            "Mozilla/5.0 (Windows NT 6.1; rv:28.0) Gecko/20100101 Firefox/28.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/29.0.1547.76 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; rv:22.0) Gecko/20100101 Firefox/22.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/31.0.1650.57 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/27.0.1453.116 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:28.0) Gecko/20100101 Firefox/28.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/39.0.2171.71 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; rv:32.0) Gecko/20100101 Firefox/32.0",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML like Gecko) Chrome/28.0.1500.72 Safari/537.36",
            "Mozilla/5.0 (compatible; proximic; +http://www.proximic.com/info/spider.php)",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML like Gecko) Chrome/33.0.1750.146 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; rv:23.0) Gecko/20100101 Firefox/23.0",


            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.1 Safari/537.36",
            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2227.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2226.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:40.0) Gecko/20100101 Firefox/40.1",
            "Mozilla/5.0 (Windows NT 6.3; rv:36.0) Gecko/20100101 Firefox/36.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10; rv:33.0) Gecko/20100101 Firefox/33.0",
            "Mozilla/5.0 (X11; Linux i586; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:31.0) Gecko/20130401 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; AS; rv:11.0) like Gecko",
            "Mozilla/5.0 (compatible, MSIE 11, Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko",
            "Mozilla/5.0 (compatible; MSIE 10.6; Windows NT 6.1; Trident/5.0; InfoPath.2; SLCC1; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729; .NET CLR 2.0.50727) 3gpp-gba UNTRUSTED/1.0",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 7.0; InfoPath.3; .NET CLR 3.1.40767; Trident/6.0; en-IN)",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; WOW64; Trident/6.0)",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/6.0)",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/5.0)",
            "Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/4.0; InfoPath.2; SV1; .NET CLR 2.0.50727; WOW64)",
            "Mozilla/5.0 (compatible; MSIE 10.0; Macintosh; Intel Mac OS X 10_7_3; Trident/6.0)",
            "Mozilla/4.0 (Compatible; MSIE 8.0; Windows NT 5.2; Trident/6.0)",
            "Mozilla/4.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/5.0)",
            "Mozilla/5.0 (Windows; U; MSIE 9.0; WIndows NT 9.0; en-US))",
            "Mozilla/5.0 (Windows; U; MSIE 9.0; Windows NT 9.0; en-US)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 7.1; Trident/5.0)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; Media Center PC 6.0; InfoPath.3; MS-RTC LM 8; Zune 4.7)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; Media Center PC 6.0; InfoPath.3; MS-RTC LM 8; Zune 4.7",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; Zune 4.0; InfoPath.3; MS-RTC LM 8; .NET4.0C; .NET4.0E)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; chromeframe/12.0.742.112)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; WOW64; Trident/5.0; .NET CLR 3.5.30729; .NET CLR 3.0.30729; .NET CLR 2.0.50727; Media Center PC 6.0)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0; .NET CLR 3.5.30729; .NET CLR 3.0.30729; .NET CLR 2.0.50727; Media Center PC 6.0)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0; .NET CLR 2.0.50727; SLCC2; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0; Zune 4.0; Tablet PC 2.0; InfoPath.3; .NET4.0C; .NET4.0E)",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Win64; x64; Trident/5.0",
            "Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0; yie8)",


            "Mozilla/5.0 (X11; Linux i686; rv:64.0) Gecko/20100101 Firefox/64.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:64.0) Gecko/20100101 Firefox/64.0",
            "Mozilla/5.0 (X11; Linux i586; rv:63.0) Gecko/20100101 Firefox/63.0",
            "Mozilla/5.0 (Windows NT 6.2; WOW64; rv:63.0) Gecko/20100101 Firefox/63.0",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/525.19 (KHTML, like Gecko) Chrome/1.0.154.53 Safari/525.19",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/525.19 (KHTML, like Gecko) Chrome/1.0.154.36 Safari/525.19",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.10 (KHTML, like Gecko) Chrome/7.0.540.0 Safari/534.10",
            "Mozilla/5.0 (Windows; U; Windows NT 5.2; en-US) AppleWebKit/534.4 (KHTML, like Gecko) Chrome/6.0.481.0 Safari/534.4",
            "Mozilla/5.0 (Macintosh; U; Intel Mac OS X; en-US) AppleWebKit/533.4 (KHTML, like Gecko) Chrome/5.0.375.86 Safari/533.4",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/532.2 (KHTML, like Gecko) Chrome/4.0.223.3 Safari/532.2",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/532.0 (KHTML, like Gecko) Chrome/4.0.201.1 Safari/532.0",
            "Mozilla/5.0 (Windows; U; Windows NT 5.2; en-US) AppleWebKit/532.0 (KHTML, like Gecko) Chrome/3.0.195.27 Safari/532.0",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/530.5 (KHTML, like Gecko) Chrome/2.0.173.1 Safari/530.5",
            "Mozilla/5.0 (Windows; U; Windows NT 5.2; en-US) AppleWebKit/534.10 (KHTML, like Gecko) Chrome/8.0.558.0 Safari/534.10",
            "Mozilla/5.0 (X11; U; Linux x86_64; en-US) AppleWebKit/540.0 (KHTML,like Gecko) Chrome/9.1.0.0 Safari/540.0",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/534.14 (KHTML, like Gecko) Chrome/9.0.600.0 Safari/534.14",
            "Mozilla/5.0 (X11; U; Windows NT 6; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.587.0 Safari/534.12",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.13 (KHTML, like Gecko) Chrome/9.0.597.0 Safari/534.13",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.16 (KHTML, like Gecko) Chrome/10.0.648.11 Safari/534.16",
            "Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US) AppleWebKit/534.20 (KHTML, like Gecko) Chrome/11.0.672.2 Safari/534.20",
            "Mozilla/5.0 (Windows NT 6.0) AppleWebKit/535.1 (KHTML, like Gecko) Chrome/14.0.792.0 Safari/535.1",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.872.0 Safari/535.2",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.7 (KHTML, like Gecko) Chrome/16.0.912.36 Safari/535.7",
            "Mozilla/5.0 (Windows NT 6.0; WOW64) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.66 Safari/535.11",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_6_8) AppleWebKit/535.19 (KHTML, like Gecko) Chrome/18.0.1025.45 Safari/535.19",
            "Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/535.24 (KHTML, like Gecko) Chrome/19.0.1055.1 Safari/535.24",
            "Mozilla/5.0 (Windows NT 6.2) AppleWebKit/536.6 (KHTML, like Gecko) Chrome/20.0.1090.0 Safari/536.6",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/22.0.1207.1 Safari/537.1",
            "Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.15 (KHTML, like Gecko) Chrome/24.0.1295.0 Safari/537.15",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.93 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/28.0.1467.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/30.0.1599.101 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1623.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/34.0.1847.116 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/37.0.2062.103 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/40.0.2214.38 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2490.71 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.62 Safari/537.36",

            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.2; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.133 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.102 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.133 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.84 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/68.0.3440.106 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.112 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.67 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.102 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.89 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.67 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.63 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.117 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.67 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.181 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.100 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36",
        ];

        return $agents[array_rand($agents, 1)];

    }

}