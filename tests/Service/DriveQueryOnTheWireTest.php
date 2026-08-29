<?php

declare(strict_types=1);

namespace Borsche\GoogleDriveDocsBundle\Tests\Service;

use Borsche\GoogleDriveDocsBundle\Contract\AllowAllViewerContext;
use Borsche\GoogleDriveDocsBundle\Service\DriveDocumentService;
use Borsche\GoogleDriveDocsBundle\Service\SpreadsheetService;
use Borsche\GoogleDriveDocsBundle\Tests\FakeViewerContext;
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

    /**
     * The `fields` mask of the request that left, split into the names Drive will fill in.
     *
     * @return list<string>
     */
    private function sentFields(int $index): array
    {
        self::assertArrayHasKey($index, $this->history, 'no request reached the transport');

        parse_str($this->history[$index]['request']->getUri()->getQuery(), $parsed);

        self::assertIsString($parsed['fields'] ?? null, 'the request carried no fields mask');

        return preg_split('/[\s,()]+/', $parsed['fields'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function testTheSharingLookupAsksDriveForTheViewOfEveryGrant(): void
    {
        // A metadata-only grant is refused on the strength of its `view`, and Drive only sends
        // the fields it was asked for: a check on a field the mask leaves out is a check on
        // nothing. The item's own permissions come back empty here, which is what a Shared
        // Drive usually does, so the second request is the dedicated permissions.list.
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"id": "doc", "parents": ["' . self::DRIVE_ID . '"]}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"permissions": []}'),
        ]));
        $stack->push(Middleware::history($this->history));
        $client = new Client();
        $client->setHttpClient(new GuzzleClient(['handler' => $stack]));

        $service = new DriveDocumentService(
            new Drive($client),
            new FakeViewerContext('viewer@example.com', false),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );

        self::assertFalse($service->canAccess('doc'));
        self::assertStringContainsString('permissions', $this->history[1]['request']->getUri()->getPath());
        self::assertContains('view', $this->sentFields(1), 'permissions.list must ask for view, or the metadata check below it is dead');
        self::assertContains('view', $this->sentFields(0), 'files.get must ask for view too');
    }

    public function testTheListingAsksForEveryFieldTheAccessWalkReads(): void
    {
        // reachableBy() climbs with getParents(), and the object it climbs from is the one this
        // listing returned — not a re-fetch. Drive fills in only what it was asked for, so a mask
        // short of `parents` ends the climb at the first step and the item is dropped in silence.
        $this->drive()->searchPage('Test');

        $fields = $this->sentFields(0);

        foreach (['id', 'parents', 'permissions', 'inheritedPermissionsDisabled'] as $needed) {
            self::assertContains(
                $needed,
                $fields,
                sprintf('the walk reads %s, so the listing has to ask Drive for it', $needed)
            );
        }
    }

    /**
     * A document reachable only through its folder has to survive the search filter.
     *
     * The fixture below answers the way Drive does — it returns `parents` only to a request that
     * asked for them — because a fixture that volunteers the field would pass against the very
     * bug this exists for.
     */
    public function testADocumentSharedThroughItsFolderIsFound(): void
    {
        $stack = HandlerStack::create($this->driveThatHonoursTheFieldsMask());
        $stack->push(Middleware::history($this->history));

        $client = new Client();
        $client->setHttpClient(new GuzzleClient(['handler' => $stack]));

        $service = new DriveDocumentService(
            new Drive($client),
            new FakeViewerContext('viewer@example.com', false),
            self::DRIVE_ID,
            ['application/vnd.google-apps.spreadsheet']
        );

        $page = $service->searchPage('Test');

        self::assertCount(
            1,
            $page,
            'the document is shared through its folder, and canAccess() says so — a search must agree'
        );
    }

    /**
     * Drive as far as the access walk is concerned: one document with no grant of its own, inside
     * one folder that carries the viewer's grant.
     *
     * The rule it enforces is the one the bundle keeps relearning — a field left out of the mask
     * comes back absent, not merely unread.
     */
    private function driveThatHonoursTheFieldsMask(): callable
    {
        return static function (\Psr\Http\Message\RequestInterface $request) {
            $path = $request->getUri()->getPath();
            parse_str($request->getUri()->getQuery(), $query);

            $asked = preg_split('/[\s,()]+/', (string) ($query['fields'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $json = static fn (array $body): \GuzzleHttp\Promise\PromiseInterface
                => \GuzzleHttp\Promise\Create::promiseFor(
                    new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))
                );

            // permissions.list on either item.
            if (str_ends_with($path, '/permissions')) {
                $subject = basename(dirname($path));

                // Only the folder carries a grant, and it is the viewer's own rather than one
                // inherited from somewhere above.
                return $json($subject === 'folder-1' ? ['permissions' => [[
                    'emailAddress'      => 'viewer@example.com',
                    'type'              => 'user',
                    'role'              => 'writer',
                    'permissionDetails' => [['inherited' => false]],
                ]]] : ['permissions' => []]);
            }

            // files.get, which is how the walk reads an ancestor.
            if (!str_ends_with($path, '/files')) {
                return $json(['id' => basename($path), 'parents' => [self::DRIVE_ID]]);
            }

            // files.list — and this is the point of the fixture.
            $file = [
                'id'       => 'doc-1',
                'name'     => 'Test document',
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ];

            if (in_array('parents', $asked, true)) {
                $file['parents'] = ['folder-1'];
            }

            return $json(['files' => [$file]]);
        };
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
