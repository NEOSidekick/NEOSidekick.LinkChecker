<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NEOSidekick\LinkChecker\Infrastructure\WebCrawlerFactory;
use Neos\Flow\Tests\UnitTestCase;
use Psr\Http\Message\RequestInterface;

class WebCrawlerFactoryTest extends UnitTestCase
{
    /** @test */
    public function externalHeadProbeDoesNotSendRangeHeader(): void
    {
        $requests = [];
        $middleware = $this->createHeadFirstMiddleware(externalRangeBytes: 65536);

        $response = $middleware(static function (RequestInterface $request) use (&$requests) {
            $requests[] = $request;

            return Create::promiseFor(new Response(200));
        })(new Request('GET', 'https://external.example/page'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertFalse($requests[0]->hasHeader('Range'));
    }

    /** @test */
    public function rejectedExternalHeadProbeFallsBackToRangedGet(): void
    {
        $requests = [];
        $middleware = $this->createHeadFirstMiddleware(externalRangeBytes: 65536);

        $response = $middleware(static function (RequestInterface $request) use (&$requests) {
            $requests[] = $request;

            return Create::promiseFor(new Response($request->getMethod() === 'HEAD' ? 416 : 206));
        })(new Request('GET', 'https://external.example/page'), [])->wait();

        self::assertSame(206, $response->getStatusCode());
        self::assertCount(2, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertFalse($requests[0]->hasHeader('Range'));
        self::assertSame('GET', $requests[1]->getMethod());
        self::assertSame('bytes=0-65536', $requests[1]->getHeaderLine('Range'));
    }

    /** @test */
    public function rangedGetReturning416IsRetriedWithoutRangeHeader(): void
    {
        $requests = [];
        $middleware = $this->createHeadFirstMiddleware(externalRangeBytes: 65536);

        $response = $middleware(static function (RequestInterface $request) use (&$requests) {
            $requests[] = $request;

            if ($request->getMethod() === 'HEAD') {
                return Create::promiseFor(new Response(405));
            }

            return Create::promiseFor(new Response($request->hasHeader('Range') ? 416 : 200));
        })(new Request('GET', 'https://external.example/page'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $requests);
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertFalse($requests[0]->hasHeader('Range'));
        self::assertSame('GET', $requests[1]->getMethod());
        self::assertSame('bytes=0-65536', $requests[1]->getHeaderLine('Range'));
        self::assertSame('GET', $requests[2]->getMethod());
        self::assertFalse($requests[2]->hasHeader('Range'));
    }

    private function createHeadFirstMiddleware(int $externalRangeBytes): callable
    {
        $method = new \ReflectionMethod(WebCrawlerFactory::class, 'createHeadFirstMiddleware');
        $method->setAccessible(true);

        return $method->invoke(new WebCrawlerFactory(), 'own.example', $externalRangeBytes);
    }
}
