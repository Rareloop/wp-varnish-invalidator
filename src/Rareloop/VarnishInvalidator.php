<?php

namespace Rareloop;

class VarnishInvalidator
{
    private static $staticInstance;

    protected $banUrls = [];
    protected $banRegexes = [];

    protected $varnishConfig = [];

    /**
     * Returns a Singleton instance of the class
     *
     * @param  Integer $port Force the port used to contact Varnish on
     * @return VarnishInvalidator
     */
    public static function instance($port = null)
    {
        if (null === static::$staticInstance) {
            static::$staticInstance = new static($port);
        }

        return static::$staticInstance;
    }

    /**
     * Constructor
     *
     * @param  Integer $port Force the port used to contact Varnish on
     */
    public function __construct($port = null)
    {
        $this->varnishConfig['port'] = $port;

        $eventsToListenOn = [
            'save_post',
            'deleted_post',
            'trashed_post',
            'edit_post',
            'delete_attachment',
            'switch_theme',
        ];

        foreach ($eventsToListenOn as $event) {
            add_action($event, [$this, 'handleChange'], 10, 2);
        }

        // When we're done, flush all the purge requests to Varnish
        add_action('shutdown', [$this, 'flush']);
    }

    /**
     * Triggers on a change event and adds the relevant URL's to the ban list
     *
     * Function inspired by Varnish HTTP Purge
     * https://github.com/Ipstenu/varnish-http-purge/blob/master/plugin/varnish-http-purge.php#L277
     *
     * @param  [type] $postId [description]
     * @return [type]         [description]
     */
    protected function handleChange($postId)
    {
        // If this is a valid post we want to purge the post, the home page and any associated tags & cats
        // If not, purge everything on the site.

        $validPostStatus = array("publish", "trash");
        $thisPostStatus  = get_post_status($postId);

        // If this is a revision, stop.
        if (get_permalink($postId) !== true && !in_array($thisPostStatus, $validPostStatus)) {
            return;
        } else {
            // array to collect all our URLs
            $listofurls = array();

            // Category purge based on Donnacha's work in WP Super Cache
            $categories = get_the_category($postId);
            if ($categories) {
                foreach ($categories as $cat) {
                    $this->invalidateUrl(get_category_link($cat->term_id));
                }
            }
            // Tag purge based on Donnacha's work in WP Super Cache
            $tags = get_the_tags($postId);
            if ($tags) {
                foreach ($tags as $tag) {
                    $this->invalidateUrl(get_tag_link($tag->term_id));
                }
            }

            // Author URL
            $this->invalidateUrl(get_author_posts_url(get_post_field('post_author', $postId)));
            $this->invalidateUrl(get_author_feed_link(get_post_field('post_author', $postId)));

            // Archives and their feeds
            $archiveurls = array();
            if (get_post_type_archive_link(get_post_type($postId)) == true) {
                $this->invalidateUrl(get_post_type_archive_link(get_post_type($postId)));
                $this->invalidateUrl(get_post_type_archive_feed_link(get_post_type($postId)));
            }

            // Post URL
            $this->invalidateUrl(get_permalink($postId));

            // Feeds
            $this->invalidateUrl(get_bloginfo_rss('rdf_url'));
            $this->invalidateUrl(get_bloginfo_rss('rss_url'));
            $this->invalidateUrl(get_bloginfo_rss('rss2_url'));
            $this->invalidateUrl(get_bloginfo_rss('atom_url'));
            $this->invalidateUrl(get_bloginfo_rss('comments_rss2_url'));
            $this->invalidateUrl(get_post_comments_feed_link($postId));

            // Home Page and (if used) posts page
            $this->invalidateUrl(home_url('/'));

            if (get_option('show_on_front') == 'page') {
                $this->invalidateUrl(get_permalink(get_option('page_for_posts')));
            }
        }

        // Filter to add or remove urls to the array of purged urls
        // @param array $purgeUrls the urls (paths) to be purged
        // @param int $postId the id of the new/edited post
        // $this->invalidateUrls = apply_filters( 'vhp_purge_urls', $this->invalidateUrls, $postId );
    }

    /**
     * Adds a URL to the list to be cleared from the cache.
     * If $url contains the hostname it will be removed and ignored
     *
     * @param  String $url The URL to remove from the cache
     */
    public function invalidateUrl($url)
    {
        // Store only the URL, not the hostname or port
        $urlParts = parse_url($url);

        $url = $urlParts['path'];

        if (isset($urlParts['query'])) {
            $url .= '?' . $urlParts['query'];
        }

        if (isset($urlParts['fragment'])) {
            $url .= '#' . $urlParts['fragment'];
        }

        // Enforce a leading slash
        if (strpos($url, '/') !== 0) {
            $url = '/' . $url;
        }

        array_push($this->banUrls, $url);
    }

    /**
     * Adds a regex pattern to the ban list
     *
     * @param  String $regex
     */
    public function invalidateRegex($regex)
    {
        array_push($this->banRegexes, $regex);
    }

    /**
     * Applies any custom Varnish config to the URL
     *
     * @param  String $url
     * @return String
     */
    protected function processUrl($url)
    {
        // Give us the chance to change the port that Varnish is running on.
        // This can be useful if we're using port forwarding (e.g. Vagrant) and
        // the URL won't resolve on the server
        $urlParts = parse_url($url);

        $port = $this->varnishConfig['port'] ?: $urlParts['port'];

        $url = $urlParts['scheme'] . '://' . $urlParts['host'] . ':' . $port;

        // Add a path if we have one
        if (isset($urlParts['path'])) {
            $url .= $urlParts['path'];
        }

        return $url;
    }

    /**
     * Attempts to send all ban requests to the Varnish server
     */
    public function flush()
    {
        $siteUrl = $this->processUrl(home_url());

        // Handle any url requests
        foreach ($this->banUrls as $url) {
            $response = wp_remote_request($siteUrl, [
                'method' => 'BAN',
                'headers' => [
                    'X-Ban-Method' => 'url',
                    'X-Ban-Url' => $url,
                ],
            ]);
        }

        // Handle any regex requests
        foreach ($this->banRegexes as $regex) {
            $response = wp_remote_request($siteUrl, [
                'method' => 'BAN',
                'headers' => [
                    'X-Ban-Method' => 'regex',
                    'X-Ban-Regex' => $regex,
                ],
            ]);
        }
    }
}
