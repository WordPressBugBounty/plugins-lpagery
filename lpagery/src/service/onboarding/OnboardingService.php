<?php
namespace LPagery\service\onboarding;
use LPagery\wpml\WpmlHelper;

class OnboardingService
{

    public static ?OnboardingService $instance = null;

    public static function get_instance(): OnboardingService
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
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

        // Add WPML language if the plugin is active
        if (WpmlHelper::is_wpml_installed()) {
            global $sitepress;
            if ($sitepress) {
                $page_data['wpml_language'] = $sitepress->get_current_language();
            }
        }

        $page_id = wp_insert_post($page_data);
        return $page_id;
    }

}