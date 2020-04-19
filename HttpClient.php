<?php

namespace wj008;


class HttpClient
{
    private $headers;
    private $responseHeaders;
    private $responseStatusLine = '';
    private $responseBody = '';
    private $checkStatus = true;
    private $options = [];
    private $responseInfo = [];
    private $isComplete = false;
    private $followLocation = false;
    private $maxRedirects = 20;
    private $redirectsFollowed = 0;

    public function __construct()
    {
        $this->headers = [];
        $this->options[CURLOPT_HEADERFUNCTION] = [$this, 'parseHeader'];
        $this->options[CURLOPT_WRITEFUNCTION] = [$this, 'parseBody'];
        if (!ini_get("open_basedir")) {
            $this->options[CURLOPT_FOLLOWLOCATION] = true;
        } else {
            $this->followLocation = true;
        }
    }

    private function parseBody($curl, $body)
    {
        $this->responseBody .= $body;
        return strlen($body);
    }

    private function parseHeader($curl, $headers)
    {
        if (!$this->responseStatusLine && strpos($headers, 'HTTP/') === 0) {
            $this->responseStatusLine = $headers;
        } else {
            $parts = explode(': ', $headers);
            if (isset($parts[1])) {
                $this->responseHeaders[$parts[0]] = trim($parts[1]);
            }
        }
        return strlen($headers);
    }

    public function setAuth($username, $password)
    {
        $this->options[CURLOPT_USERPWD] = "$username:$password";
    }

    public function checkStatus($option = true)
    {
        $this->checkStatus = $option;
    }

    public function setTimeout($timeout)
    {
        $this->options[CURLOPT_TIMEOUT] = $timeout;
        $this->options[CURLOPT_CONNECTTIMEOUT] = $timeout;
    }

    public function setProxy($host, $port = false)
    {
        $this->options[CURLOPT_PROXY] = $host;
        if ($port) {
            $this->options[CURLOPT_PROXYPORT] = $port;
        }
    }

    public function setVerifyPeer($peer = false)
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = $peer;
    }

    public function setSslCertificate($path, $password = false)
    {
        $this->options[CURLOPT_SSLCERT] = $path;
        if ($password) {
            $this->options[CURLOPT_SSLCERTPASSWD] = $password;
        }
    }

    public function setSslVersion($version)
    {
        $this->options[CURLOPT_SSLVERSION] = $version;
    }

    public function setHeaders($headers)
    {
        foreach ($headers as $header => $value) {
            $this->setHeader($header, $value);
        }
    }

    public function setHeader($header, $value)
    {
        $this->headers[] = $header . ': ' . $value;
    }

    public function setUserAgent($value)
    {
        $this->setHeader('User-Agent', $value);
    }

    private function initRequest()
    {
        $this->isComplete = false;
        $this->responseBody = "";
        $this->responseHeaders = [];
        $this->options[CURLOPT_HTTPHEADER] = $this->headers;
        $ch = curl_init();
        curl_setopt_array($ch, $this->options);
        $this->isComplete = true;
        return $ch;
    }

    private function checkResponse($ch)
    {
        $this->responseInfo = curl_getinfo($ch);
        if (curl_errno($ch)) {
            throw new HttpClientException(curl_error($ch), curl_errno($ch));
        }
        if ($this->checkStatus) {
            $status = $this->getStatus();
            if ($status >= 400 && $status <= 599) {
                throw new HttpClientException($this->getStatusMessage(), $status);
            }
        }
        if ($this->followLocation) {
            $this->followRedirectPath($ch);
        }
    }

    private function followRedirectPath($ch)
    {
        $this->redirectsFollowed++;
        if ($this->getStatus() == 301 || $this->getStatus() == 302) {
            if ($this->redirectsFollowed < $this->maxRedirects) {
                $location = $this->getHeader('Location');
                $forwardTo = parse_url($location);
                if (isset($forwardTo['scheme']) && isset($forwardTo['host'])) {
                    $url = $location;
                } else {
                    $forwardFrom = parse_url(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
                    $url = $forwardFrom['scheme'] . '://' . $forwardFrom['host'] . $location;
                }
                $this->get($url);
            } else {
                $errorString = "Too many redirects when trying to follow location.";
                throw new HttpClientException($errorString, CURLE_TOO_MANY_REDIRECTS);
            }
        } else {
            $this->redirectsFollowed = 0;
        }
    }

    public function get($uri, $query = null)
    {
        $ch = $this->initRequest();

        if (is_array($query)) {
            $uri .= "?" . http_build_query($query);
        } elseif ($query) {
            $uri .= "?" . $query;
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_exec($ch);
        $this->checkResponse($ch);
        curl_close($ch);
        return $this;
    }

    public function post($uri, $data)
    {
        $ch = $this->initRequest();
        if (is_array($data)) {
            $data = http_build_query($data);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_exec($ch);
        $this->checkResponse($ch);
        curl_close($ch);
        return $this;
    }

    public function head($uri)
    {
        $ch = $this->initRequest();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $this->checkResponse($ch);
        curl_close($ch);
        return $this;
    }

    public function put($uri, $data)
    {
        $ch = $this->initRequest();
        $handle = tmpfile();
        fwrite($handle, $data);
        fseek($handle, 0);
        curl_setopt($ch, CURLOPT_INFILE, $handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_exec($ch);
        $this->checkResponse($ch);
        curl_close($ch);
        return $this;
    }

    public function delete($uri)
    {
        $ch = $this->initRequest();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_exec($ch);
        $this->checkResponse($ch);
        curl_close($ch);
        return $this;
    }

    /**
     * 获取状态码
     * @return int
     */
    public function getStatus()
    {
        return intval($this->responseInfo['http_code']);
    }

    /**
     * 获取状态消息内容
     * @return string
     */
    public function getStatusMessage()
    {
        return $this->responseStatusLine;
    }

    /**
     * 获取内容
     * @return string
     */
    public function getBody()
    {
        return $this->responseBody;
    }

    /**
     * 获取头部信息
     * @param $header
     * @return mixed
     */
    public function getHeader($header = null)
    {
        if (empty($header)) {
            return $this->responseHeaders;
        }
        if (array_key_exists($header, $this->responseHeaders)) {
            return $this->responseHeaders[$header];
        }
    }

    /**
     * 获取返回信息
     * @return array
     */
    public function getInfo()
    {
        return $this->responseInfo;
    }

    public function getOptions()
    {
        return $this->options;
    }

}
