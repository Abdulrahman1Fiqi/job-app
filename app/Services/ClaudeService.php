<?php

namespace App\Services;

use GuzzleHttp\Client;

class ClaudeService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'headers' => [
                'x-api-key' => env('CLAUDE_API_KEY'),
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],
        ]);
    }

    public function sendMessage(array $data)
    {
        return $this->client->post('v1/messages', [
            'json' => $data
        ]);
    }
}