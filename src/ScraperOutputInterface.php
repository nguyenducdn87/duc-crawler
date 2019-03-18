<?php

namespace DucCrawler;

interface ScraperOutputInterface
{
    public function beforeProxyChange(AmazonScraper $scraper) : bool;
    public function afterProxyChange(AmazonScraper $scraper, string $new_proxy = null);
    public function error(AmazonScraper $scraper, \Throwable $error);
    public function message($message, int $verbosity = 32);
    public function requestError(AmazonScraper $scraper, \GuzzleHttp\Psr7\Request $request);
    public function clientCreating(AmazonScraper $scraper, array $config) : array ;
    public function clientCreated(AmazonScraper $scraper, \GuzzleHttp\Client $client) : \GuzzleHttp\Client;
}