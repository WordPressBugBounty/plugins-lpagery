<?php

namespace LPagery\service\image_endpoint;

/**
 * The injected HTTP-transport seam for the {@see EdgeCacheProbe} (issue #233): a thin GET wrapper that
 * fetches a URL through the site's **public front door** — normal DNS resolution of the `home_url()`-based
 * probe URL, so the request traverses whatever CDN / caching layer sits in front of the origin. This is
 * deliberately NOT the loopback transport {@see EndpointHealthTransport} uses (which relaxes SSL and short-
 * circuits DNS to measure the origin directly): the whole point of the Edge Cache Probe is to observe how
 * an intermediary treats the response, so it must go the same way a real visitor's browser would.
 *
 * Kept dumb — it classifies nothing. It only reports whether the fetch produced a clean response (status
 * code + Content-Type); the absorbed-vs-not decision comes from the origin-side hit counter
 * ({@see EdgeCacheProbeStore::get_hits()}), never from response headers. A WP_Error (timeout, DNS failure,
 * refused connection) returns `ok=false` (inconclusive), which the probe retries later.
 */
class EdgeCacheProbeTransport
{
    /**
     * Fetch one probe URL through the public front door.
     *
     * @return array{ok:bool, code:int, content_type:string} `ok=false` means the request produced no
     *   definite response (WP_Error) and is inconclusive; otherwise `code` and `content_type` carry
     *   the result.
     */
    public function fetch(string $url): array
    {
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            // Measure the endpoint itself, not a redirect chain: a 3xx is inconclusive, not evidence.
            'redirection' => 0,
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'code' => 0, 'content_type' => '');
        }
        return array(
            'ok' => true,
            'code' => (int)wp_remote_retrieve_response_code($response),
            'content_type' => (string)wp_remote_retrieve_header($response, 'content-type'),
        );
    }
}
