<?php

namespace LPagery\suite;

class SuiteOAuthAuthorizeHandler
{
    public static function maybe_handle(): void
    {
        if (isset($_GET['page']) && $_GET['page'] === 'lpagery' && isset($_GET['authorize']) && $_GET['authorize'] === 'true') {

            if (!is_user_logged_in()) {
                // Get the current URL
                $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

                // Create the login URL with proper redirect
                $login_url = wp_login_url($current_url);
                // Redirect to login
                wp_redirect($login_url);
                exit;
            }

            // Generate a unique code for this authorization
            $code = wp_generate_password(32, false);
            $user_id = get_current_user_id();

            // Store the code with user ID and additional data in transients
            $nonce = wp_create_nonce('suite_oauth_' . $code);
            set_transient('suite_oauth_code_' . $code, [
                'user_id' => $user_id,
                'timestamp' => time(),
                'app_user_mail_address' => sanitize_email($_GET["app_user_mail_address"]),
                'nonce' => $nonce
            ], 300);


            $suite_origin = 'https://app.lpagery.io';
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>LPagery Authorization</title>
            </head>
            <body>
                <script>
                    (function() {
                        const code = '<?php echo esc_js($code); ?>';
                        const nonce = '<?php echo esc_js($nonce); ?>';
                        const user_id = '<?php echo esc_js(strval($user_id)); ?>';
                        if (window.opener) {
                            window.opener.postMessage({
                                type: 'wordpress_auth',
                                nonce:nonce,
                                user_id:user_id,
                                code:code
                            }, '<?php echo esc_js($suite_origin); ?>');

                        }
                    })();
                </script>
                <p>Authorization completed. You can close this window.</p>
            </body>
            </html>
            <?php
            exit;
        }
    }
}
