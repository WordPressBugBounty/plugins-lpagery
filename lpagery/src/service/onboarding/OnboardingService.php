<?php
namespace LPagery\service\onboarding;

use LPagery\multilingual\MultilingualPlugin;

class OnboardingService
{
    private ?MultilingualPlugin $multilingualPlugin;

    public function __construct(?MultilingualPlugin $multilingualPlugin)
    {
        $this->multilingualPlugin = $multilingualPlugin;
    }

    public function createOnboardingTemplatePage()
    {
        $example_title = "Example Page Title: The Best {service} in {city}";

        $path = plugin_dir_path(__FILE__) . '../../../static/onboarding-template.html';
        if(!file_exists($path)) {
            return null;
        }
        $example_content = file_get_contents($path);

        $page_data = array(
            'post_title'    => $example_title,
            'post_content'  => $example_content,
            'post_status'   => 'draft',
            'post_type'     => 'page'
        );

        $page_id = wp_insert_post($page_data);

        // The Template Page starts out in the language the admin is browsing, so the Page Sets built
        // from it inherit that Page Language. Set through the adapter, after the insert, so no
        // plugin-specific argument leaks into $page_data.
        $admin_language = $this->multilingualPlugin === null ? null : $this->multilingualPlugin->get_current_admin_language();
        if ($page_id && $admin_language !== null) {
            $this->multilingualPlugin->set_post_language((int)$page_id, $admin_language);
        }

        return $page_id;
    }

}
