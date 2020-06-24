<?php

namespace WithCandour\AardvarkSeo\Http\Controllers\Web;


use Illuminate\Routing\Controller as LaravelController;

class SitemapController extends LaravelController
{
    public function index()
    {
        return 'Sitemap';
    }

    /**
     * Return the xsl file required for our sitemap views
     */
    public function xsl()
    {
        $path = __DIR__ . '/../../../../resources/xsl/sitemap.xsl';
        return response(file_get_contents($path))->header('Content-Type', 'text/xsl');
    }
}