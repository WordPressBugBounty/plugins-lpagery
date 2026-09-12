<?php

namespace LPagery\multilingual;

/**
 * SQL pieces a Multilingual Plugin needs spliced into LPagery's own queries so a post row can
 * carry its Page Language (template search) or be scoped to one (duplicate-slug lookup).
 *
 * The fragments are plugin-specific but always describe the same three things: a select
 * expression yielding the language code, a JOIN that makes it reachable, and a WHERE condition
 * for one language code. Callers must treat $select and $join as trusted plugin-authored SQL and
 * pass the language code through $wpdb->prepare via the placeholder in the where condition.
 *
 * Every caller concatenates these into one string and hands it to $wpdb->prepare(), so an adapter
 * MUST honour three rules or it silently corrupts the query:
 *
 * 1. $select has to alias the column to `language_code` - that is the name every consumer reads
 *    off the result row (see {@see \LPagery\io\Mapper::lpagery_map_post()}).
 * 2. $where carries exactly one %s placeholder (the language code); $select and $join carry none,
 *    because callers hard-code how many arguments they pass to prepare().
 * 3. No fragment may contain a literal % - write it %% so prepare() leaves it alone.
 */
class LanguageSqlFragments
{
    /** Select expression yielding the language code, aliased to `language_code` (see rule 1). */
    public string $select;

    /** JOIN clause bringing the language table/taxonomy into the query; carries no placeholder. */
    public string $join;

    /** WHERE condition for one language, carrying a single %s placeholder for the code. */
    public string $where;

    public function __construct(string $select, string $join, string $where)
    {
        $this->select = $select;
        $this->join = $join;
        $this->where = $where;
    }
}
