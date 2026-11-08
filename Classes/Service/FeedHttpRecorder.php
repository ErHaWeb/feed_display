<?php

/**
 * This file is part of the "feed_display" Extension for TYPO3 CMS.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

declare(strict_types=1);

namespace ErHaWeb\FeedDisplay\Service;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 * @phpstan-type FeedHttpStep array{uri: string, status: int, headers: array<string, list<string>>, body: string}
 */
final class FeedHttpRecorder implements ClientInterface
{
    public const MAX_BODY_SIZE = 2 * 1024 * 1024;
    private const HEADERS = ['content-type', 'content-encoding', 'location', 'link', 'content-language'];

    /** @var list<FeedHttpStep> */
    private array $steps = [];
    private int $bodySize = 0;

    public function __construct(
        private readonly GuzzleClientInterface $client,
        private readonly ?float $timeout = null,
        private readonly ?float $connectTimeout = null,
        private readonly ?int $maxBodySize = self::MAX_BODY_SIZE,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string)$request->getUri();
        if ($request->getMethod() !== 'GET' || !in_array(strtolower($request->getUri()->getScheme()), ['http', 'https'], true)) {
            throw new RequestException('Expected an HTTP(S) GET feed request.', $request);
        }

        $options = ['allow_redirects' => false, 'http_errors' => false];
        if ($this->timeout !== null) {
            $options['timeout'] = $this->timeout;
            $options['connect_timeout'] = $this->connectTimeout ?? $this->timeout;
        }
        $response = $this->client->send($request, $options);
        $stream = $response->getBody();
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = Utils::copyToString($stream, $this->maxBodySize === null ? -1 : $this->maxBodySize - $this->bodySize + 1);
        } finally {
            $stream->close();
        }
        $this->bodySize += strlen($body);
        if ($this->maxBodySize !== null && $this->bodySize > $this->maxBodySize) {
            throw new RequestException('The feed responses exceed the 2 MiB response limit.', $request);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), self::HEADERS, true)) {
                $headers[$name] = array_values($values);
            }
        }

        $status = $response->getStatusCode();
        $this->steps[] = ['uri' => $uri, 'status' => $status, 'headers' => $headers, 'body' => $body];

        return new Response($status, $headers, $body);
    }

    public function received(string $body): bool
    {
        return in_array($body, array_column($this->steps, 'body'), true);
    }
}
