<?php

const VERBOSITY_QUIET = 16;
const VERBOSITY_NORMAL = 32;
const VERBOSITY_VERBOSE = 64;
const VERBOSITY_VERY_VERBOSE = 128;
const VERBOSITY_DEBUG = 256;

require "vendor/autoload.php";

$verbosity = 64;
$class = new SalvatorePruiti\AmazonScraper(function($message, $verbosity_level) use ($verbosity) {

    switch ($verbosity_level) {
        case 32:
            $verb = "INFO";
            break;
        case 64:
            $verb = "VERBOSE";
            break;
    }

    if($verbosity >= $verbosity_level)
        printf("%s - %s - %s\n", $verb ?? 'INFO', date('d/m/Y H:i:s'), $message);

});

$class = new \SalvatorePruiti\AmazonScraper();

$class->setProxies([]);



$response = $class->changeAddress("it", "DE");

var_dump($class->getLastPageBody());