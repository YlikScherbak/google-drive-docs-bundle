<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Sheets;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * What Drive actually receives, rather than what the bundle passed to the client.
 *
 * Every other test in this suite stubs `Files::listFiles($optParams)` and asserts the `q` handed
 * over there. That is one layer too early to see the bug this file exists for: the client
 * URL-decodes each query parameter before re-encoding it, so a `%27` that the bundle's escaping
 * left alone arrives at Drive as a bare quote and closes the string literal. The assertion has to
 * be on the request that leaves the process.
 *
 * The same blindness let a wrong fix ship in 1.0.2 — the test there read the object it had just
 * written to instead of the body. Both are the same lesson: assert the wire, and the wire is
 * whatever the transport hands to Google, not the argument the bundle hands to the SDK.
 */
final class DriveQueryOnTheWireTest extends TestCase
{
    private const DRIVE_ID = 'SHARED_DRIVE_ID';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface, response: mixed}> */
    private array $history = [];

    /**
     * The `q` Drive's own parser would see, taken from the request that actually left.
     *
     * Reading it back with `parse_str` is deliberate: it is the server's side of the same
     * percent-encoding, so whatever survives here is what Drive interprets.
     */
    private function sentQuery(int $index = 0): string
    {
        self::assertArrayHasKey($index, $this->history, 'no request reached the transport');

        parse_str($this->history[$index]['request']->getUri()->getQuery(), $parsed);

        self::assertIsString($parsed['q'] ?? null, 'the request carried no q parameter');

        return $parsed['q'];
    }

    /** @return list<string> the ranges as Sheets would read them */
    private function sentRanges(int $index = 0): array
    {
        self::assertArrayHasKey($index, $this->history, 'no request reached the transport');

        $ranges = [];

        foreach (explode('&', $this->history[$index]['request']->getUri()->getQuery()) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if ($name === 'ranges') {
                $ranges[] = rawurldecode($value);
            }
        }

        return $ranges;
    }

    private function client(string $json): Client
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $json),
        ]));
        $stack->push(Middleware::history($this->history));

        $client = new Client();
        $client->setHttpClient(new GuzzleClient(['handler' => $stack]));

        return $client;
    }

    private function drive(): DriveDocumentService
    {
        return new DriveDocumentService(
            new Drive($this->client('{"files": []}')),
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );
    }

    public function testAPercentEncodedQuoteCannotCloseTheStringLiteral(): void
    {
        // The payload carries no literal quote, so escaping the two characters the bundle used to
        // escape leaves it untouched — and the client's rawurldecode() turns %27 into one.
        $this->drive()->search("zzz%27 or fullText contains %27password");

        $q = $this->sentQuery();

        self::assertStringNotContainsString(
            "or fullText contains 'password'",
            $q,
            'a search term appended its own clause to the Drive query'
        );
        self::assertStringContainsString('%27', $q, 'the payload should reach Drive as literal text');
    }

    public function testAPercentEncodedQuoteCannotBreakOutOfAnAppPropertyLookup(): void
    {
        // The worse half of the same defect: this one is usually a server-side lookup, where the
        // value is an order id and the result is trusted to be that order's document.
        $this->drive()->findByAppProperty(
            'orderId',
            "4711%27 } or name=%27Salary.xlsx%27 or appProperties has { key=%27x%27 and value=%27y"
        );

        $q = $this->sentQuery();

        self::assertStringNotContainsString(
            "name='Salary.xlsx'",
            $q,
            'an app-property value chose a different file — a confused deputy'
        );
    }

    public function testALiteralQuoteIsStillEscapedAndStillSearchable(): void
    {
        // The control: the escaping that already worked must go on working.
        $this->drive()->search("Bob's list");

        self::assertStringContainsString("name contains 'Bob\\'s list'", $this->sentQuery());
    }

    public function testAPercentSignSurvivesAsItself(): void
    {
        // And the reason a blanket strip would be wrong: a per cent sign is ordinary text in a
        // file name, and the search for it has to keep finding it.
        $this->drive()->search('50% off');

        $q = $this->sentQuery();

        self::assertStringContainsString("name contains '50% off'", $q);
    }

    public function testASheetRangeCannotBreakOutOfTheBatchGetQuery(): void
    {
        // Sheets takes its ranges the same way, through the same encoder. Only correctness is at
        // stake here rather than access, but a range that rewrites itself is still a defect.
        $client = $this->client('{"valueRanges": []}');
        $drive  = new DriveDocumentService(
            new Drive($client),
            new AllowAllViewerContext(),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );

        (new SpreadsheetService(new Sheets($client), $drive))->readMany('sheet-1', ["Q3!A1", "Q3!A%27B"]);

        self::assertSame(['Q3!A1', 'Q3!A%27B'], $this->sentRanges());
    }
}
