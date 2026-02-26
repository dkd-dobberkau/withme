<?php

declare(strict_types=1);

namespace T3WithMe\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $allowedOrigin) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            return $this->addCorsHeaders($response);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response);
    }

    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Last-Event-ID')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
