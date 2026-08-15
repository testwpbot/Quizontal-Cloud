<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SeoController extends Controller
{
    /**
     * Serve a dynamically generated sitemap.xml. The base URL is read from
     * config('app.url') so the sitemap always reflects the live domain — no
     * hardcoded host that drifts when the site moves between environments.
     */
    public function sitemap(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $lastmod = now()->toDateString();

        // Path => [change frequency, priority]
        $pages = [
            '' => ['weekly', '1.0'],
            'hosting' => ['weekly', '0.9'],
            'vps' => ['weekly', '0.9'],
            'domains' => ['weekly', '0.9'],
            'pricing' => ['weekly', '0.8'],
        ];

        $urls = '';
        foreach ($pages as $path => [$changefreq, $priority]) {
            $loc = $path === '' ? $base . '/' : $base . '/' . $path;
            $urls .= "  <url>\n"
                . "    <loc>" . e($loc) . "</loc>\n"
                . "    <lastmod>{$lastmod}</lastmod>\n"
                . "    <changefreq>{$changefreq}</changefreq>\n"
                . "    <priority>{$priority}</priority>\n"
                . "  </url>\n";
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $urls
            . "</urlset>\n";

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Serve robots.txt pointing crawlers at the dynamic sitemap.
     */
    public function robots(): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        $body = "User-agent: *\n"
            . "Allow: /\n"
            . "\n"
            . "Sitemap: {$base}/sitemap.xml\n";

        return response($body, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
