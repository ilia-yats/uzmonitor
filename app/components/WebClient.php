<?php


namespace App\components;


use App\Exceptions\WebRequestFailedException;

class WebClient
{
    public $sessionId = '';
    public $referrer = 'https://booking.uz.gov.ua/ru/';

    protected function headers()
    {
        return [
            "Accept: */*",
            "Accept-Encoding: gzip, deflate, br",
            "Accept-Language: uk,ru-RU;q=0.9,ru;q=0.8,en-US;q=0.7,en;q=0.6",
            "Cache-Control: no-cache",
            "cache-version: 755",
            "Connection: close",
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "Cookie: _gv_lang=ru; HTTPSERVERID=server2; _gv_sessid=".$this->sessionId."",
            "Host: booking.uz.gov.ua",
            "Origin: https://booking.uz.gov.ua",
            "Pragma: no-cache",
            "Referer: ".$this->referrer."",
            "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36",
            "X-Requested-With: XMLHttpRequest",
        ];
    }

    public function get(string $url, array $headers = null): string
    {
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => implode("\r\n", $headers ?: $this->headers()),
            ],
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
        ];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);

        if (!$this->checkIfRequestSucceed($http_response_header)) {
            throw new WebRequestFailedException('unexpected response status: '.$response);
        }

        foreach ($http_response_header as $headerLine) {
            if (preg_match('~_gv_sessid=([^;]+);~', $headerLine, $match)) {
                $this->sessionId = $match[1];
            }
        }

        $this->referrer = $url;
        return $response;
    }

    public function post(string $url, $data, array $headers = null, bool $raw = false): string
    {
        $opts = [
            "http" => [
                "method" => "POST",
                "header" => implode("\r\n", $headers ?: $this->headers()),
                "content" => $raw ? $data : http_build_query($data)
            ],
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
        ];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);

        if (!$this->checkIfRequestSucceed($http_response_header)) {
            throw new WebRequestFailedException('unexpected response status: '.$response);
        }

        return $response;

    }

    //public function login($login, $pwd)
    //{
    //    $opts = [
    //        "http" => [
    //            "method" => "POST",
    //            "header" => $this->headers(),
    //            "content" => http_build_query([
    //                'email' => $login,
    //                'pwd' => $pwd,
    //            ])
    //        ]
    //    ];
    //    $context = stream_context_create($opts);
    //
    //    file_get_contents('https://booking.uz.gov.ua/ru/authorization/login/', false, $context);
    //
    //    foreach ($http_response_header as $headerLine) {
    //        if (preg_match('~_gv_sessid=([^;]+);~', $headerLine, $match)) {
    //            $this->sessionId = $match[1];
    //            return true;
    //        }
    //    }
    //
    //    return false;
    //}

    protected function checkIfRequestSucceed(array $responseHeaders): bool
    {
        $statusLine = $responseHeaders[0];

        preg_match('~HTTP[^\s]*\s(\d{3})~', $statusLine, $match);

        $status = $match[1];

        return ($status === "200");
    }
}
