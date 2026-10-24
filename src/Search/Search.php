<?php

declare(strict_types=1);

namespace Campanella\Search;

use Campanella\Access\Actor;
use Campanella\Capability\PublishStatus;
use Campanella\Database\Connection;
use Campanella\Database\Schema\CoreSchema;
use Campanella\Database\Schema\SchemaReader;
use Campanella\Model\BlueprintRegistry;
use Campanella\Model\FieldType;
use Campanella\Query\Query;
use Campanella\Query\QueryEngine;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The site's search (since 0.2.7), with the database's FULLTEXT indexes over the
 * `search_index` table (SearchIndex). Every word must be found, as the start of a
 * word ("kert" finds "kertben"); a match in the title counts more. Only what is
 * published is found, for everyone; then the Query engine's access rules apply to
 * the page's objects as to any query.
 */
final class Search
{
    /** The shortest word searched for (InnoDB's default `innodb_ft_min_token_size`). */
    public const int MIN_WORD = 3;

    /** The most words of a query. */
    public const int MAX_WORDS = 8;

    /** The longest query read, in characters. */
    public const int MAX_QUERY = 200;

    /** The most results ranked. */
    public const int MAX_RESULTS = 500;

    /** The length of an excerpt, in characters. */
    public const int EXCERPT = 220;

    public function __construct(
        private readonly Connection $db,
        private readonly QueryEngine $queries,
        private readonly BlueprintRegistry $blueprints,
    ) {
    }

    /**
     * The stop words of InnoDB's FULLTEXT index (its default list): they are not in the
     * index, and a required one would find nothing, so a query leaves them out.
     */
    public const array STOP_WORDS = ['about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    /**
     * The words of a query: lowercase and without accents (SearchIndex::fold()), letters
     * and digits, at least MIN_WORD long, not a stop word, each once, at most MAX_WORDS.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $query = SearchIndex::fold(mb_substr($query, 0, self::MAX_QUERY, 'UTF-8'));
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word, 'UTF-8') >= self::MIN_WORD && !in_array($word, self::STOP_WORDS, true) && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return array_slice($words, 0, self::MAX_WORDS);
    }

    public function find(string $query, Actor $actor, int $page = 1, int $perPage = 10): SearchResult
    {
        $perPage = max(1, min(50, $perPage));
        // An absurdly large page number is an empty page, not an overflow (since 0.3.0).
        $page = min(max(1, $page), intdiv(self::MAX_RESULTS, $perPage) + 2);
        $words = self::words($query);
        if ($words === [] || !(new SchemaReader($this->db))->tableExists(CoreSchema::SEARCH_INDEX)) {
            return new SearchResult($query, $words, [], 0, $page, $perPage);
        }
        $against = implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words));
        $params = ['q1' => $against, 'q2' => $against, 'q3' => $against];
        $published = '';
        if ((new SchemaReader($this->db))->tableExists('cap_publishable')) {
            $published = " AND (NOT EXISTS (SELECT 1 FROM {object_capabilities} c WHERE c.object_id = s.object_id AND c.capability = 'publishable')
                OR EXISTS (SELECT 1 FROM {cap_publishable} p WHERE p.object_id = s.object_id AND p.status = :status AND p.published_at <= :now))";
            $params['status'] = PublishStatus::Published->value;
            $params['now'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(FieldType::STORAGE_DATE_FORMAT);
        }
        $ids = array_map(intval(...), $this->db->fetchColumn(
            'SELECT s.object_id FROM {search_index} s
             WHERE MATCH(s.title, s.content) AGAINST(:q3 IN BOOLEAN MODE)' . $published . '
             ORDER BY MATCH(s.title) AGAINST(:q1 IN BOOLEAN MODE) * 3 + MATCH(s.title, s.content) AGAINST(:q2 IN BOOLEAN MODE) DESC, s.object_id DESC
             LIMIT ' . self::MAX_RESULTS,
            $params,
        ));
        $pageIds = array_slice($ids, ($page - 1) * $perPage, $perPage);
        $items = [];
        if ($pageIds !== []) {
            $objects = [];
            foreach ($this->queries->execute(Query::objects()->where('id', 'IN', $pageIds)->limit($perPage), $actor)->items as $object) {
                $objects[(int) $object->id()] = $object;
            }
            foreach ($pageIds as $id) {
                if (isset($objects[$id])) {
                    // The excerpt from the object's text itself (the index has it without accents).
                    [, $text] = SearchIndex::text($objects[$id], $this->blueprints->find($objects[$id]->blueprint()));
                    $items[] = ['object' => $objects[$id], 'excerpt' => self::excerpt($text, $words)];
                }
            }
        }

        return new SearchResult($query, $words, $items, count($ids), $page, $perPage);
    }

    /**
     * A part of the text around the first word found, cut into pieces: the words that
     * start with a word searched for (compared without accents) are marked (`mark`), to
     * be shown highlighted. Plain text, to be escaped when shown.
     *
     * @param list<string> $words As words() gives them
     * @return list<array{text: string, mark: bool}>
     */
    public static function excerpt(string $text, array $words): array
    {
        if ($text === '') {
            return [];
        }
        $matches = static function (string $token) use ($words): bool {
            $folded = SearchIndex::fold($token);
            foreach ($words as $word) {
                if (str_starts_with($folded, $word)) {
                    return true;
                }
            }

            return false;
        };
        // The first word found, in characters.
        $start = 0;
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $tokens, PREG_OFFSET_CAPTURE);
        foreach ($tokens[0] as [$token, $at]) {
            if ($matches($token)) {
                $start = max(0, mb_strlen(substr($text, 0, $at), 'UTF-8') - 60);
                break;
            }
        }
        $part = mb_substr($text, $start, self::EXCERPT, 'UTF-8');
        if ($start > 0) {
            $part = '…' . ltrim(preg_replace('/^\S*\s/u', '', $part) ?? $part);
        }
        if ($start + self::EXCERPT < mb_strlen($text, 'UTF-8')) {
            $part = rtrim(preg_replace('/\s\S*$/u', '', $part) ?? $part) . '…';
        }
        $pieces = [];
        $offset = 0;
        preg_match_all('/[\p{L}\p{N}]+/u', $part, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[0] as [$token, $at]) {
            if (!$matches($token)) {
                continue;
            }
            if ($at > $offset) {
                $pieces[] = ['text' => substr($part, $offset, $at - $offset), 'mark' => false];
            }
            $pieces[] = ['text' => $token, 'mark' => true];
            $offset = $at + strlen($token);
        }
        if ($offset < strlen($part)) {
            $pieces[] = ['text' => substr($part, $offset), 'mark' => false];
        }

        return $pieces;
    }
}
