<?php
declare(strict_types=1);

final class Sources
{
    /** Geographic diversity buckets: key => [label, short label, scope, region, country] */
    public const GEO = [
        'us' => ['United States · National', 'US', 'national', null, 'US'],
        'us-ne' => ['United States · Northeast', 'US Northeast', 'regional', 'Northeast', 'US'],
        'us-ny' => ['United States · New York', 'New York', 'local', 'New York', 'US'],
        'es' => ['Spain · National', 'Spain', 'national', null, 'ES'],
        'es-vc' => ['Spain · Valencian Community', 'C. Valenciana', 'regional', 'Valencian Community', 'ES'],
        'es-alc' => ['Spain · Alicante', 'Alicante', 'local', 'Alicante', 'ES'],
        'eu' => ['European Union', 'EU', 'continental', null, 'EU'],
        'uk' => ['United Kingdom', 'UK', 'national', null, 'GB'],
        'europe' => ['Europe · National', 'Europe', 'national', null, null],
        'intl' => ['International', 'International', 'international', null, 'INT'],
    ];

    public const STATUSES = ['pending', 'approved', 'rejected', 'blocked'];
    public const SCOPES = ['local', 'regional', 'national', 'continental', 'international'];
    public const TYPES = ['newspaper', 'broadcaster', 'magazine', 'digital', 'wire', 'public', 'institution', 'blog'];
    public const LANGUAGES = ['en' => 'English', 'es' => 'Spanish'];

    public const EDITABLE = ['name', 'domain', 'homepage_url', 'feed_url', 'country', 'region', 'geo_group', 'scope', 'language', 'source_type', 'default_category', 'notes'];

    public static function geoLabel(?string $geo, bool $short = false): string
    {
        return self::GEO[$geo][$short ? 1 : 0] ?? (string) $geo;
    }

    public static function find(int $id): ?array
    {
        return q('SELECT * FROM sources WHERE id = ?', [$id])->fetch() ?: null;
    }

    public static function findByFeed(string $feedUrl): ?array
    {
        return q('SELECT * FROM sources WHERE feed_url = ?', [$feedUrl])->fetch() ?: null;
    }

    /** Insert seed sources that aren't in the database yet (matched by feed URL). */
    public static function importSeed(): int
    {
        $seed = require APP_DIR . '/sources_seed.php';
        $added = 0;
        foreach ($seed as $s) {
            if (self::findByFeed($s['feed'])) {
                continue;
            }
            self::create([
                'name' => $s['name'],
                'feed_url' => $s['feed'],
                'homepage_url' => $s['home'] ?? null,
                'geo_group' => $s['geo'],
                'language' => $s['lang'],
                'source_type' => $s['type'] ?? 'newspaper',
                'default_category' => $s['cat'] ?? null,
                'country' => $s['country'] ?? null,
                'region' => $s['region'] ?? null,
            ], 'approved', 'seed');
            $added++;
        }
        return $added;
    }

    /** Create a source; geography fields not supplied are derived from geo_group. */
    public static function create(array $data, string $status, string $via): int
    {
        $data = self::withDefaults($data);
        $now = now_utc();
        q(
            'INSERT INTO sources (name, domain, homepage_url, feed_url, feed_type, country, region, geo_group, scope, language, source_type, default_category, notes, status, is_active, discovered_via, discovered_at, created_at, updated_at)
             VALUES (:name, :domain, :homepage_url, :feed_url, :feed_type, :country, :region, :geo_group, :scope, :language, :source_type, :default_category, :notes, :status, 1, :via, :now, :now, :now)',
            [
                'name' => $data['name'], 'domain' => $data['domain'], 'homepage_url' => $data['homepage_url'],
                'feed_url' => $data['feed_url'], 'feed_type' => $data['feed_type'] ?? 'rss',
                'country' => $data['country'], 'region' => $data['region'], 'geo_group' => $data['geo_group'],
                'scope' => $data['scope'], 'language' => $data['language'], 'source_type' => $data['source_type'],
                'default_category' => $data['default_category'], 'notes' => $data['notes'] ?? null,
                'status' => $status, 'via' => $via, 'now' => $now,
            ]
        );
        return (int) db()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $data = self::withDefaults($data);
        $sets = [];
        $params = ['id' => $id, 'now' => now_utc()];
        foreach (self::EDITABLE as $field) {
            $sets[] = "{$field} = :{$field}";
            $params[$field] = $data[$field] ?? null;
        }
        q('UPDATE sources SET ' . implode(', ', $sets) . ', updated_at = :now WHERE id = :id', $params);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (in_array($status, self::STATUSES, true)) {
            q('UPDATE sources SET status = ?, updated_at = ? WHERE id = ?', [$status, now_utc(), $id]);
        }
    }

    public static function setActive(int $id, bool $active): void
    {
        $sql = $active
            ? "UPDATE sources SET is_active = 1, fail_count = 0, retry_after = NULL, access_status = CASE WHEN access_status = 'failing' THEN 'unknown' ELSE access_status END, updated_at = ? WHERE id = ?"
            : 'UPDATE sources SET is_active = 0, updated_at = ? WHERE id = ?';
        q($sql, [now_utc(), $id]);
    }

    private static function withDefaults(array $d): array
    {
        $geo = isset(self::GEO[$d['geo_group'] ?? '']) ? $d['geo_group'] : 'intl';
        [, , $scope, $region, $country] = self::GEO[$geo];
        $trim = static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $d['geo_group'] = $geo;
        $d['name'] = $trim($d['name'] ?? null) ?? (Urls::domain($d['feed_url'] ?? '') ?? 'Untitled source');
        $d['feed_url'] = trim((string) ($d['feed_url'] ?? ''));
        $d['homepage_url'] = $trim($d['homepage_url'] ?? null);
        $d['domain'] = $trim($d['domain'] ?? null) ?? Urls::domain($d['homepage_url'] ?? $d['feed_url']);
        $d['scope'] = in_array($d['scope'] ?? null, self::SCOPES, true) ? $d['scope'] : $scope;
        $d['region'] = $trim($d['region'] ?? null) ?? $region;
        $d['country'] = $trim(isset($d['country']) ? strtoupper((string) $d['country']) : null) ?? $country;
        $d['language'] = isset(self::LANGUAGES[$d['language'] ?? '']) ? $d['language'] : 'en';
        $d['source_type'] = in_array($d['source_type'] ?? null, self::TYPES, true) ? $d['source_type'] : 'newspaper';
        $d['default_category'] = isset(Categories::LABELS[$d['default_category'] ?? '']) ? $d['default_category'] : null;
        $d['notes'] = $trim($d['notes'] ?? null);
        return $d;
    }
}
