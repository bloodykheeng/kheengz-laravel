<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * A photo given as a link (a shop's product picture) instead of an upload. We download it once and keep our
 * own copy on the public disk, exactly where an upload lands, so the page never depends on the other site:
 * a site that renames, removes or blocks the image cannot break a live product page (GiftNest's docs/photos-from-links.md; Kheengz writes its own with products).
 *
 * Fetching a URL someone typed is a classic server-side request forgery hole, so: http(s) only, the host
 * (and every redirect's host) must resolve to public addresses, three redirects at most, 20 seconds, 20 MB,
 * and the bytes must really be a JPG, PNG, WebP or GIF image (checked from the content, not the name).
 */
class ImageFromLink
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** @var Closure(string): list<string> host name to its IP addresses */
    private Closure $resolve;

    /** @param  (Closure(string): list<string>)|null  $resolve  Swapped in tests, so they need no network. */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? fn (string $host): array => gethostbynamel($host) ?: [];
    }

    /**
     * Download the image at `$url` into `$folder` on the public disk and return its path.
     *
     * @throws ValidationException on `$field`, in words the person who pasted the link can act on.
     */
    public function store(string $url, string $folder, string $field): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            $this->fail($field, 'Paste the full image link, starting with https://');
        }
        $this->assertPublic((string) $parts['host'], $field);

        try {
            $response = Http::timeout(20)
                ->connectTimeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Kheengz image import)', 'Accept' => 'image/*'])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => fn ($request, $response, UriInterface $to) => $this->assertPublic($to->getHost(), $field),
                    ],
                    'progress' => function ($total, $downloaded) use ($field) {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            $this->fail($field, 'That image is larger than 20 MB.');
                        }
                    },
                ])
                ->get($url);
        } catch (ValidationException $e) {
            throw $e;
        } catch (ConnectionException) {
            $this->fail($field, 'We could not reach that site. Check the link, or save the picture and upload it instead.');
        } catch (Throwable $e) {
            // Guzzle wraps an exception thrown in a callback; the message we threw is the one to show.
            $inner = $e->getPrevious();
            if ($inner instanceof ValidationException) {
                throw $inner;
            }
            $this->fail($field, 'We could not download that image. Save the picture and upload it instead.');
        }

        return $this->keep($response, $url, $folder, $field);
    }

    private function keep(Response $response, string $url, string $folder, string $field): string
    {
        if (! $response->successful()) {
            $this->fail($field, "That site would not give us the image (it answered {$response->status()}). Save the picture and upload it instead.");
        }

        $bytes = $response->body();
        if (strlen($bytes) > self::MAX_BYTES) {
            $this->fail($field, 'That image is larger than 20 MB.');
        }

        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        $extension = $info ? (self::TYPES[$info['mime']] ?? null) : null;
        if ($extension === null) {
            $this->fail($field, 'That link is not a JPG, PNG, WebP or GIF image. Open the picture itself, copy its address, and paste that.');
        }

        $base = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $name = substr(preg_replace('/[^A-Za-z0-9_\-]/', '_', $base) ?: 'photo', 0, 30).'_'.uniqid().'.'.$extension;
        $path = trim($folder, '/').'/'.$name;
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /** Every address the host resolves to must be public: no localhost, private network or cloud metadata. */
    private function assertPublic(string $host, string $field): void
    {
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolve)($host);

        $public = $ips !== [] && collect($ips)->every(
            fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        );

        if (! $public) {
            $this->fail($field, $ips === [] ? 'We could not find that site. Check the link.' : 'That link does not point to a public website.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
