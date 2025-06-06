<?php
namespace LPagery\io\suite;

class SuiteClient
{

    private static ?SuiteClient $instance = null;
    public static function get_instance(): SuiteClient
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function disconnect_page_set($page_set_id)
    {
         $this->perform_request('page_sets/' . $page_set_id . '/disconnect');

    }

    public function trigger_look_sync($page_set_id, bool $overwrite_manual_changes)
    {
        $params = [];
        $params['overwriteManualChanges'] = $overwrite_manual_changes;
        return $this->perform_request('page_sets/' . $page_set_id . '/sync/trigger', $params);

    }


    private function perform_request(string $path, array $query_params = [])
    {
        $install_id = lpagery_fs()->get_site()->id;
        $site_private_key = lpagery_fs()->get_site()->secret_key;

        $nonce = date('Y-m-d');
        $pk_hash = hash('sha512', $site_private_key . '|' . $nonce);
        $authentication_string = base64_encode($pk_hash . '|' . $nonce);

        // Build query parameters
        $default_params = ['websiteId' => $install_id];
        $all_params = array_merge($default_params, $query_params);
        $query_string = http_build_query($all_params);

        $url = 'https://app.lpagery.io/api/wordpress_plugin/v1/' . $path . '?' . $query_string;
        $response = wp_remote_request($url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $authentication_string,
            ],
        ]);
        if(is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        if($response['response']['code'] !== 200) {
            throw new \Exception($response['body']);
        }

        return json_decode($response['body'], true);
    }

}