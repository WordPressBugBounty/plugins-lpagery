<?php

namespace LPagery\service\preflight;

use LPagery\utils\Utils;

/**
 * What the Template Page contains, read once per Pre-flight Check run: its title, content, excerpt
 * and post meta (page builder data such as Elementor's included), as plain text pieces.
 *
 * A Placeholder here is a strict `{token}` of letters, digits, `_`, `-`, `.` and inner spaces, so
 * JSON objects, CSS rules and Spintax (`{a|b}`) are never mistaken for one.
 */
class TemplateScan
{
    private const PLACEHOLDER = '/\{([\p{L}\p{N}_.\-]+(?: +[\p{L}\p{N}_.\-]+)*)\}/u';

    private string $text;
    /** @var array<string, int>|null normalized Placeholder => index */
    private ?array $normalized_placeholders = null;
    /** @var array<string, true> the pieces that are just a number, like a featured image id */
    private array $numeric_pieces = [];

    /**
     * @param string[] $pieces
     */
    public function __construct(array $pieces)
    {
        $text = implode("\n", $pieces);
        if (!preg_match('//u', $text)) {
            // A stray byte that isn't UTF-8 would make every Unicode pattern fail, so it becomes U+FFFD.
            $text = htmlspecialchars_decode(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
        }
        $this->text = self::unescape_json($text);
        foreach ($pieces as $piece) {
            if (ctype_digit(trim($piece))) {
                $this->numeric_pieces[trim($piece)] = true;
            }
        }
    }

    /**
     * Page builders store their data as JSON, where `/` may be `\/` and `ß` may be `\u00df`.
     */
    private static function unescape_json(string $text): string
    {
        $text = str_replace('\/', '/', $text);
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            $char = json_decode('"' . $match[0] . '"');
            return is_string($char) ? $char : $match[0];
        }, $text) ?? $text;
    }

    /**
     * Every distinct Placeholder token, in the order the template first uses it.
     *
     * @return string[]
     */
    public function placeholders(): array
    {
        preg_match_all(self::PLACEHOLDER, $this->text, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Whether the template uses the column's Placeholder, ignoring case and sanitization the same
     * way the slug check does.
     */
    public function uses_column(string $column): bool
    {
        if ($this->normalized_placeholders === null) {
            $this->normalized_placeholders = array_flip(array_map([self::class, 'normalize'], $this->placeholders()));
        }
        return isset($this->normalized_placeholders[self::normalize($column)]);
    }

    /**
     * Whether the template shows the attachment, the way the run finds it to swap it per page: its
     * file in any size (`hero.jpg`, `hero-300x200.jpg`), or its id in a block, shortcode or class
     * (`wp-image-12`, `"id":12`, `="12"`, `attachment='12'`), or a meta value that is just the id,
     * like a featured image.
     *
     * @param string $attached_file the `_wp_attached_file` path, e.g. `2024/05/hero-scaled.jpg`
     */
    public function references_attachment(int $id, string $attached_file): bool
    {
        if (isset($this->numeric_pieces[(string)$id])) {
            return true;
        }
        $id_pattern = '/(?:wp-image-' . $id . '|":' . $id . '|="' . $id . '"|attachment=\'' . $id . '\')(?!\d)/';
        if (preg_match($id_pattern, $this->text)) {
            return true;
        }
        $stem = preg_replace('/-scaled$/i', '', pathinfo(basename($attached_file), PATHINFO_FILENAME));
        if ($stem === null || $stem === '') {
            return false;
        }
        $file_pattern = '/(?<![\p{L}\p{N}_.\-])' . preg_quote($stem, '/') . '(?:-scaled)?(?:-\d+x\d+)?\.[a-z0-9]+/iu';
        return (bool)preg_match($file_pattern, $this->text);
    }

    /**
     * The media-library images the template shows, by the filename an Image column would be named
     * after: size and `-scaled` suffixes dropped, each once.
     *
     * @return string[]
     */
    public function image_filenames(): array
    {
        preg_match_all('#/uploads/(?:[^\s"\'<>()/]+/)*([^\s"\'<>()/?\#]+?)(?:-\d+x\d+)?(?:-scaled)?\.(png|jpe?g|heic|gif|svg|webp)(?![a-z0-9])#i',
            $this->text, $matches, PREG_SET_ORDER);
        $filenames = [];
        foreach ($matches as $match) {
            $filenames[strtolower($match[1] . '.' . $match[2])] = $match[1] . '.' . $match[2];
        }
        return array_values($filenames);
    }

    /**
     * A column key or Placeholder token as both are compared: braces dropped, sanitized, lowercase.
     */
    public static function normalize(string $token): string
    {
        return strtolower(Utils::lpagery_sanitize_title_with_dashes(trim($token, '{}')));
    }
}
