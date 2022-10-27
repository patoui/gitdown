<?php

namespace GitDown;

use GuzzleHttp\Client;

class GitDown
{
    protected $token;
    protected $context;
    protected $allowedTags;
    protected $theme;

    public function __construct($token = null, $context = null, $allowedTags = [], $theme = 'light', Client $client = null)
    {
        $this->token = $token;
        $this->context = $context;
        $this->allowedTags = $allowedTags;
        $this->theme = $theme;
        $this->client = $client ?? new Client();
    }

    public function setToken($token)
    {
        $this->token = $token;

        return $this;
    }

    public function setContext($context)
    {
        $this->context = $context;

        return $this;
    }

    public function withTags($allowedTags = [])
    {
        $this->allowedTags = $allowedTags;

        return $this;
    }

    public function parse($content)
    {
        $response = $this->client->request('POST', 'https://api.github.com/markdown', [
            'headers' => [
                'User-Agent' => 'GitDown Plugin',
            ] + ($this->token ? ['Authorization' => 'token '.$this->token] : []),
            'json' => [
                'text' => $this->encryptAllowedTags($content),
            ]
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 300) {
            throw new \Exception('GitHub API Error: ' . ((string) $response->getBody()));
        }

        return $this->decryptAllowedTags((string) $response->getBody());
    }

    public function encryptAllowedTags($input)
    {
        if (! count($this->allowedTags)) {
            return $input;
        }

        foreach ($this->allowedTags as $tag) {
            if (! preg_match_all("/<{$tag}[^>]*?(?:\/>|>[^<]*?<\/{$tag}>)/", $input, $matches)) {
                continue;
            };

            foreach ($matches[0] as $match) {
                $input = str_replace($match, "\[{$tag}\]" . base64_encode($match) . "\[end{$tag}\]", $input);
            }
        }

        return $input;
    }

    public function decryptAllowedTags($input)
    {
        if (! count($this->allowedTags)) {
            return $input;
        }

        foreach ($this->allowedTags as $tag) {

            if (! preg_match_all("/\[{$tag}\].*\[end{$tag}\]/", $input, $matches)) {
                continue;
            };

            foreach ($matches[0] as $match) {
                $input = str_replace($match, base64_decode(ltrim(rtrim($match, "[end{$tag}]"), "[{$tag}]")), $input);
            }
        }

        return $input;
    }

    public function parseAndCache($content, $minutes = null)
    {
        if (is_null($minutes)) {
            return cache()->rememberForever(sha1($content), function () use ($content) {
                return $this->parse($content);
            });
        }

        if (is_callable($minutes)) {
            return $minutes($this->generateParseCallback($content));
        }

        return cache()->remember(
            sha1($content),
            $minutes,
            function () use ($content) {
                return $this->parse($content);
            }
        );
    }

    protected function generateParseCallback($content)
    {
        return function () use ($content) {
            return $this->parse($content);
        };
    }

    public function styles()
    {
        return file_get_contents(
            implode(DIRECTORY_SEPARATOR, [
                __DIR__, '..', 'dist', 'styles-'.$this->theme.'.css',
            ])
        );
    }
}
