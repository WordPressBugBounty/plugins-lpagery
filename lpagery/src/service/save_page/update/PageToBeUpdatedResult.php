<?php
namespace LPagery\service\save_page\update;

use LPagery\service\save_page\SavePageResult;
use WP_Post;

class PageToBeUpdatedResult
{
    public ?WP_Post $post;
    public ?SavePageResult $pageResult;

    public function __construct (?WP_Post $post, ?SavePageResult $pageResult)
    {
        $this->post = $post;
        $this->pageResult = $pageResult;
    }


}