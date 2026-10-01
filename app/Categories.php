<?php
declare(strict_types=1);

/**
 * Maps whatever categories a source supplies (and the article URL path) onto a small fixed set.
 * Words are matched whole-word against folded (lowercase, accent-free) text.
 */
final class Categories
{
    public const LABELS = [
        'politics' => 'Politics',
        'world' => 'World',
        'business' => 'Business',
        'technology' => 'Technology',
        'science' => 'Science',
        'health' => 'Health',
        'environment' => 'Climate',
        'culture' => 'Culture',
        'sports' => 'Sports',
        'local' => 'Local',
        'opinion' => 'Opinion',
        'general' => 'General',
    ];

    // Order matters: the first category whose words match wins.
    private const WORDS = [
        'opinion' => ['opinion', 'opinions', 'editorial', 'editorials', 'op ed', 'oped', 'columns', 'column', 'columnists', 'commentary', 'tribuna', 'firmas', 'columnas', 'cartas al director', 'blogs'],
        'sports' => ['sport', 'sports', 'deporte', 'deportes', 'football', 'futbol', 'soccer', 'nba', 'nfl', 'mlb', 'nhl', 'baloncesto', 'basketball', 'tennis', 'tenis', 'golf', 'formula 1', 'f1', 'motor', 'motogp', 'ciclismo', 'cycling', 'cricket', 'rugby', 'olympics', 'laliga', 'la liga', 'champions', 'premier league', 'valencia cf', 'hercules', 'athletics', 'boxing'],
        'technology' => ['tech', 'technology', 'technologies', 'tecnologia', 'tecnologias', 'internet', 'gadgets', 'ai', 'artificial intelligence', 'inteligencia artificial', 'ia', 'software', 'apps', 'cybersecurity', 'ciberseguridad', 'digital', 'innovation', 'innovacion', 'startups', 'computing', 'smartphones', 'moviles', 'videojuegos', 'gaming', 'redes sociales', 'social media', 'retina'],
        'science' => ['science', 'sciences', 'ciencia', 'ciencias', 'space', 'espacio', 'astronomy', 'astronomia', 'physics', 'fisica', 'biology', 'biologia', 'research', 'investigacion', 'nasa', 'materia'],
        'health' => ['health', 'salud', 'medicine', 'medicina', 'sanidad', 'wellness', 'bienestar', 'coronavirus', 'covid', 'mental health', 'salud mental', 'public health', 'nutrition', 'nutricion'],
        'environment' => ['climate', 'clima', 'climate change', 'cambio climatico', 'environment', 'environmental', 'medio ambiente', 'medioambiente', 'energy', 'energia', 'sostenibilidad', 'sustainability', 'planeta', 'planet', 'weather', 'tiempo', 'el tiempo', 'meteorologia', 'natureza', 'wildlife', 'green'],
        'business' => ['business', 'economy', 'economia', 'economics', 'markets', 'mercados', 'finance', 'finanzas', 'companies', 'empresas', 'money', 'dinero', 'jobs', 'empleo', 'trabajo', 'work', 'industry', 'industria', 'banking', 'banca', 'real estate', 'vivienda', 'housing', 'inmobiliario', 'consumo', 'consumer', 'trade', 'comercio', 'agriculture', 'agricultura', 'turismo', 'tourism', 'bolsa', 'stocks', 'wall street', 'personal finance', 'fiscal', 'impuestos', 'taxes'],
        'culture' => ['culture', 'cultura', 'arts', 'art', 'arte', 'books', 'libros', 'literature', 'literatura', 'music', 'musica', 'film', 'films', 'movies', 'cine', 'television', 'tv', 'series', 'entertainment', 'entretenimiento', 'celebrity', 'celebrities', 'gente', 'famosos', 'style', 'estilo', 'lifestyle', 'fashion', 'moda', 'food', 'gastronomia', 'comer', 'travel', 'viajes', 'ocio', 'espectaculos', 'theatre', 'theater', 'teatro', 'design', 'diseno', 'history', 'historia', 'religion', 'fiestas', 'hogueras', 'fallas', 'society', 'sociedad', 'life', 'vida', 'people'],
        'local' => ['local', 'locales', 'metro', 'nyregion', 'ny region', 'new york', 'nyc', 'brooklyn', 'queens', 'bronx', 'manhattan', 'staten island', 'long island', 'new jersey', 'connecticut', 'massachusetts', 'boston', 'philadelphia', 'comunitat valenciana', 'comunidad valenciana', 'comunitat', 'valencia', 'alicante', 'alacant', 'elche', 'benidorm', 'torrevieja', 'castellon', 'provincia', 'municipios', 'ciudad', 'city', 'region', 'regional', 'comarcas', 'l alacanti', 'vega baja', 'marina alta', 'marina baixa', 'baix vinalopo'],
        'world' => ['world', 'world news', 'mundo', 'internacional', 'international', 'global', 'europe', 'europa', 'eu', 'ue', 'union europea', 'european union', 'americas', 'america', 'latinoamerica', 'latin america', 'asia', 'africa', 'middle east', 'oriente proximo', 'oriente medio', 'ukraine', 'ucrania', 'russia', 'rusia', 'china', 'israel', 'gaza', 'foreign affairs', 'foreign policy', 'geopolitics', 'geopolitica', 'us news', 'uk news', 'estados unidos', 'eeuu'],
        'politics' => ['politics', 'political', 'politica', 'politicas', 'elections', 'election', 'elecciones', 'electoral', 'government', 'gobierno', 'congress', 'congreso', 'senate', 'senado', 'parliament', 'parlamento', 'white house', 'casa blanca', 'supreme court', 'justice', 'justicia', 'tribunales', 'courts', 'law', 'legal', 'policy', 'immigration', 'inmigracion', 'democrats', 'republicans', 'national', 'nacional', 'espana', 'us politics', 'uk politics', 'westminster', 'brussels', 'bruselas', 'moncloa', 'corts', 'generalitat', 'ayuntamiento', 'city hall', 'albany', 'governor', 'mayor', 'alcalde', 'president', 'presidente', 'defense', 'defensa', 'security', 'seguridad', 'crime', 'sucesos', 'police', 'policia', 'tribunal'],
    ];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? ucfirst($key);
    }

    /** @param list<string> $raw categories/tags supplied by the source */
    public static function classify(array $raw, ?string $url, ?string $sourceDefault): string
    {
        foreach ($raw as $value) {
            if ($cat = self::match((string) $value)) {
                return $cat;
            }
        }
        if ($url && ($cat = self::match(self::urlSections($url)))) {
            return $cat;
        }
        return $sourceDefault && isset(self::LABELS[$sourceDefault]) ? $sourceDefault : 'general';
    }

    public static function match(string $text): ?string
    {
        static $patterns = null;
        if ($patterns === null) {
            foreach (self::WORDS as $cat => $words) {
                $patterns[$cat] = '/(?:^| )(?:' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)) . ')(?: |$)/';
            }
        }
        $text = trim(search_text($text));
        if ($text === '') {
            return null;
        }
        foreach ($patterns as $cat => $pattern) {
            if (preg_match($pattern, $text)) {
                return $cat;
            }
        }
        return null;
    }

    /** First two meaningful path segments, e.g. "/espana/comunidad-valenciana/2026-..." → "espana comunidad valenciana". */
    private static function urlSections(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        array_pop($parts); // the last segment is usually the article slug
        $segments = array_filter($parts, fn ($s) => !preg_match('/\d{3,}|\.html?$/', $s) && substr_count($s, '-') < 3 && strlen($s) < 40);
        return implode(' ', array_slice($segments, 0, 2));
    }
}
