<?php
namespace LPagery\service\media;
use finfo;
use LPagery\service\substitution\SubstitutionHandler;
use LPagery\data\LPageryDao;
use LPagery\model\BaseParams;
use Throwable;

class AttachmentSaver
{

    private static ?AttachmentSaver $instance = null;
    private LPageryDao $lpageryDao;
    private SubstitutionHandler $substitutionHandler;

    public function __construct(LPageryDao $lpageryDao, SubstitutionHandler $substitutionHandler)
    {
        $this->lpageryDao = $lpageryDao;
        $this->substitutionHandler = $substitutionHandler;
    }

    public static function get_instance(LPageryDao $lpageryDao, SubstitutionHandler $substitutionHandler)
    {
        if (null === self::$instance) {
            self::$instance = new self($lpageryDao, $substitutionHandler);
        }
        return self::$instance;
    }



    public function lpagery_download_and_save_attachment($image_url, $source_attachment, BaseParams $params)
    {
        $valid_image_endings = array('png', 'jpg', 'jpeg', 'heic', 'gif', 'svg', 'webp');
        $lpagery_replace_filename = get_post_meta($source_attachment["ID"], "_lpagery_replace_filename", true);
        if ($lpagery_replace_filename) {
            $filename = $this->substitutionHandler->lpagery_substitute($params, $lpagery_replace_filename);
        } else {
            return null;
        }
        $title = $source_attachment["post_title"];
        $replaced_title = $this->substitutionHandler->lpagery_substitute($params, $title);

        $filename_without_extension = pathinfo($filename, PATHINFO_FILENAME);
        $filename_without_extension = sanitize_file_name($filename_without_extension);
        $filename_without_extension = strtolower($filename_without_extension);
        $existing_image = $this->lpageryDao->lpagery_search_attachment(ltrim($filename_without_extension, "/"), true);

        if (!empty($existing_image)) {
            return (array)$existing_image[0];
        }
        $request_host = parse_url($image_url, PHP_URL_HOST);

        $args = array('timeout' => 15, 'headers' => array('User-Agent' => 'curl/8.4.0', 'Accept' => '*/*', 'Host' => $request_host));
        $response = wp_remote_get($image_url, $args);
        $is_wp_error = is_wp_error($response);

        if (!$is_wp_error && is_array( $response ) ) {
            $image_data = wp_remote_retrieve_body($response);
        } else {
            $error_message = $is_wp_error ? $response->get_error_message() : 'Unknown error occurred.';
            error_log("Failed to Download Image " . $image_url . " " . $error_message);
            return null;
        }
        if(empty($image_data)){
            return null;
        }
        $mime_type = $this->getMimeType($image_data);
        if(!$mime_type || strpos($mime_type, "/") === false){
            return null;
        }
        $extension = explode('/', $mime_type)[1];
        if (!in_array($extension, $valid_image_endings)) {
            return null;
        }

        $filename = $filename_without_extension . "." . $extension;

        $wp_upload_dir = wp_upload_dir();
        $wp_upload_dir_path = $wp_upload_dir['path'];
        $unique_filename = wp_unique_filename($wp_upload_dir_path, $filename);
        $upload_path = $wp_upload_dir_path . '/' . $unique_filename;
        $this->writeFile($upload_path, $image_data);
        $replaced_content = $this->substitutionHandler->lpagery_substitute($params, $source_attachment["post_content"]);
        $replaced_excerpt = $this->substitutionHandler->lpagery_substitute($params, $source_attachment["post_excerpt"]);

        // Prepare attachment data
        $attachment = array('post_title' => $replaced_title, 'post_content' => $replaced_content, 'post_excerpt' => $replaced_excerpt, 'post_status' => 'inherit', 'post_mime_type' => $mime_type, 'post_type' => 'attachment', 'guid' => $wp_upload_dir['url'] . '/' . $unique_filename, 'post_parent' => 0,);

        // Insert the attachment into the Media library
        $attachment_id = wp_insert_attachment($attachment, $upload_path);

        // Set the title and alt tags

        $alt_tag = get_post_meta($source_attachment["ID"], "_wp_attachment_image_alt", true);
        $replaced_alt = $this->substitutionHandler->lpagery_substitute($params, $alt_tag);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $replaced_alt);
        update_post_meta($attachment_id, '_lpagery_download_url', $image_url);

        $attach_data = wp_generate_attachment_metadata($attachment_id, $upload_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return array("ID" => $attachment_id);
    }

    public function get_or_copy_image($name_with_brackets, $attachment, BaseParams $params)
    {
        $name_with_brackets = basename($name_with_brackets);
        $wp_upload_dir = wp_upload_dir();
        $source_id = $attachment["ID"];
        $imgMeta = wp_get_attachment_metadata($source_id);

        $imgMime = $imgMeta['sizes']['thumbnail']['mime-type'];
        $absolutePath = "$wp_upload_dir[basedir]/$imgMeta[file]";

        $new_file_name = $this->substitutionHandler->lpagery_substitute($params, $name_with_brackets);

        $new_file_name = sanitize_file_name($new_file_name);

        $subdir = $wp_upload_dir["subdir"];
        $new_file_name = $subdir . "/" . $new_file_name;

        $new_absolute_path = str_replace("/" . $attachment["file_name"], $new_file_name, $absolutePath);

        $new_file_name = str_replace("//", "/", $new_file_name);
        $existing_image = $this->lpageryDao->lpagery_search_attachment(ltrim($new_file_name, "/"));

        $attachment_exists = $this->fileExists($new_absolute_path);
        if (!empty($existing_image) && $attachment_exists) {
            return (array)$existing_image[0];
        }

        if (!$attachment_exists) {
            $this->copyImage($absolutePath, $new_absolute_path);
        }

        $replace_passed_attachment = $this->substitutionHandler->lpagery_substitute($params, $attachment);
        $path_parts = pathinfo($new_file_name);

        $new_attachment = array('guid' => "$wp_upload_dir[url]/$new_file_name", 'post_mime_type' => $imgMime, 'post_title' => $replace_passed_attachment["post_title"], 'post_status' => 'inherit', 'post_excerpt' => $replace_passed_attachment["post_excerpt"], 'post_content' => $replace_passed_attachment["post_content"], 'post_content_filtered' => $replace_passed_attachment["post_content_filtered"], 'post_name' => $path_parts["filename"],);


        $image_id = wp_insert_attachment($new_attachment, $new_absolute_path);

        $attach_data = wp_generate_attachment_metadata($image_id, $new_absolute_path);
        wp_update_attachment_metadata($image_id, $attach_data);
        $alt_tag = get_post_meta($source_id, "_wp_attachment_image_alt", true);
        if ($alt_tag) {
            update_post_meta($image_id, "_wp_attachment_image_alt", $this->substitutionHandler->lpagery_substitute($params, $alt_tag));
        }
        $found_image = $this->lpageryDao->lpagery_search_attachment_by_id($image_id);
        return (array)$found_image[0];
    }

    public function fileExists($path): bool
    {
        return file_exists($path);
    }


    public function copyImage(string $absolutePath, string $new_absolute_path): bool
    {
        return copy($absolutePath, $new_absolute_path);
    }

    public function getMimeType($content): string
    {
        try{
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $mimeType = finfo_buffer($finfo, $content);

            finfo_close($finfo);
            return $mimeType;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            return "";
        }

    }


    public function writeFile(string $upload_path, string $image_data)
    {
        return file_put_contents($upload_path, $image_data);
    }
}