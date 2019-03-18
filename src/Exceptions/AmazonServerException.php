<?php

namespace DucCrawler\Exceptions;

use Psr\Http\Message\ResponseInterface;

class AmazonServerException extends \Exception
{
    private $response;

    public function __construct(ResponseInterface $response, $message = "", $code = 0, $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse()
    {
        return $this->response;
    }
}