<?php

namespace LPagery\service\image_endpoint;

/**
 * The injected HTTP-transport seam for the {@see EndpointHealthProbe} (ADR 0014): a thin loopback GET
 * wrapper so the probe never reaches into wp_remote_get directly and every probe unit test can declare
 * the response shape. Kept deliberately dumb — it classifies nothing; it only reports whether the round
 * trip produced a definite HTTP response (`ok`) and, if so, the status code and Content-Type the probe
 * needs for its strict verdict.
 *
 * A WP_Error (timeout, connection refused, blocked loopback — the WP-Cron problem) returns `ok=false`
 * (inconclusive), which the probe treats as "retain the previous verdict": hosts that block loopback
 * must never falsely mark Endpoint Health failed. Cookies are stripped and redirects disabled so the
 * probe measures the raw endpoint, not a login wall or a redirect chain.
 */
class EndpointHealthTransport
{
    /**
     * Fetch one Virtual Image URL over loopback HTTP.
     *
     * @return array{ok:bool, code:int, content_type:string} `ok=false` means the request produced no
     *   definite response (WP_Error) and is inconclusive; otherwise `code`/`content_type` carry the result.
     */
    public function fetch(string $url): array
    {
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'redirection' => 0,
            // Same default as WP core's own self-requests (wp-cron), but overridable: hosts/plugins can
            // opt this loopback probe into strict certificate checks via the standard filter.
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies' => array(),
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
