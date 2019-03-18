<?php

namespace DucCrawler;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AmazonChangeAddressResponse
 * @property int $isValidAddress
 * @property string $locationType
 * @property string $district
 * @property string $zipCode
 * @property string $addressId
 * @property string $isDefaultShippingAddress
 * @property string $obfuscatedId
 * @property string $isAccountAddress
 * @property string $state
 * @property string $countryCode
 * @property string $addressLabel
 * @property string $city
 * @property string $addressLine1
 * @package App
 */
class AmazonChangeAddressAddress
{
    public $isValidAddress;
    public $locationType;
    public $district;
    public $zipCode;
    public $addressId;
    public $isDefaultShippingAddress;
    public $obfuscatedId;
    public $isAccountAddress;
    public $state;
    public $countryCode;
    public $addressLabel;
    public $city;
    public $addressLine1;

    public function __construct($data = [])
    {
        foreach($data as $key => $value) {
            $this->$key = $value;
        }
    }
}