<?php

namespace DucCrawler;

/**
 * Class AmazonChangeAddressResponse
 * @property int $isValidAddress
 * @property AmazonChangeAddressAddress $address
 * @property int $sembuUpdated
 * @package App
 */
class AmazonChangeAddressResponse
{
    public $isValidAddress;
    public $address;
    public $sembuUpdated;

    public function __construct($data = [])
    {
        foreach($data as $key => $value) {

            switch ($key) {
                case "address":
                    $value = $value instanceof AmazonChangeAddressAddress ? $value : new AmazonChangeAddressAddress($value);
                    break;
            }

            $this->$key = $value;
        }
    }
}
