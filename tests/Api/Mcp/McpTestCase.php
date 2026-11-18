<?php

declare(strict_types=1);

namespace App\Tests\Api\Mcp;

use App\Tests\Api\ApiTestCase;

/**
 * Talks to the MCP server as a client does: JSON-RPC messages posted to
 * /mcp with a token, after the handshake that opens a session.
 */
abstract class McpTestCase extends ApiTestCase
{
    private ?string $session = null;
    private int $id = 0;

    /**
     * Sends one JSON-RPC request and returns the whole answer: `result`,
     * or `error`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function rpc(string $method, array $params, ?string $secret): array
    {
        if (null === $this->session && 'initialize' !== $method) {
            $this->rpc('initialize', [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ], $secret);
            $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $secret);
        }

        $this->post(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params], $secret);
        $response = $this->client->getResponse();
        $this->session ??= $response->headers->get('Mcp-Session-Id');
        $content = (string) $response->getContent();
        // An answer may come as a stream of events: the message is its data.
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/event-stream') && 1 === preg_match('/^data: (.+)$/m', $content, $matches)) {
            $content = $matches[1];
        }

        // The firewall refuses a token in plain text (401): nothing to read there.
        if ('' === $content || str_contains((string) $response->headers->get('Content-Type'), 'text/plain')) {
            return [];
        }

        return (array) json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Calls a tool and returns its result: `content`, `structuredContent`
     * and `isError`.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function call(string $tool, array $arguments, string $secret): array
    {
        $answer = $this->rpc('tools/call', ['name' => $tool, 'arguments' => [] === $arguments ? new \stdClass() : $arguments], $secret);
        self::assertArrayHasKey('result', $answer, json_encode($answer, \JSON_THROW_ON_ERROR));

        return $answer['result'];
    }

    /**
     * The names of the tools the token is offered.
     *
     * @return list<string>
     */
    protected function tools(string $secret): array
    {
        return array_column($this->rpc('tools/list', [], $secret)['result']['tools'], 'name');
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(array $message, ?string $secret): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $secret) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$secret;
        }
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode($message, \JSON_THROW_ON_ERROR));
    }
}
