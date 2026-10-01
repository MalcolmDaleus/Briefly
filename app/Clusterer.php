<?php
declare(strict_types=1);

/**
 * Lightweight story clustering: articles whose titles share enough distinctive words
 * (weighted by rarity within the recent window) join the same cluster.
 * Tokens are folded and cut to 5 characters, which also lets many English/Spanish
 * cognates meet (migration/migración → "migra").
 */
final class Clusterer
{
    private const STOPWORDS = [
        // English
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has', 'have', 'his', 'how', 'its', 'may', 'new', 'now', 'old', 'see', 'two', 'who', 'did', 'get', 'got', 'him', 'let', 'say', 'says', 'said', 'she', 'too', 'use', 'with', 'from', 'this', 'that', 'what', 'when', 'where', 'which', 'will', 'would', 'could', 'should', 'into', 'over', 'after', 'before', 'about', 'than', 'then', 'them', 'they', 'their', 'there', 'these', 'those', 'been', 'being', 'were', 'more', 'most', 'some', 'such', 'only', 'other', 'also', 'just', 'like', 'amid', 'why', 'here', 'your', 'first', 'last', 'year', 'years', 'week', 'day', 'days', 'time', 'back', 'make', 'makes', 'made', 'take', 'takes', 'while', 'amid', 'against', 'under', 'between', 'during', 'without', 'through', 'still', 'news', 'live', 'update', 'updates', 'video', 'watch', 'photos', 'report', 'says', 'set', 'off', 'big', 'top', 'way', 'people', 'man', 'woman', 'could', 'many', 'much', 'very', 'know', 'what', 'latest', 'bulletin', 'things', 'need',
        // Spanish
        'que', 'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'por', 'para', 'con', 'sin', 'sobre', 'entre', 'hasta', 'desde', 'como', 'pero', 'mas', 'sus', 'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'eso', 'esos', 'esas', 'aquel', 'son', 'fue', 'era', 'han', 'hay', 'ser', 'sido', 'tras', 'ante', 'contra', 'segun', 'cuando', 'donde', 'quien', 'quienes', 'cual', 'cuales', 'muy', 'todo', 'todos', 'toda', 'todas', 'otro', 'otra', 'otros', 'otras', 'ya', 'aun', 'tambien', 'solo', 'asi', 'nuevo', 'nueva', 'nuevos', 'nuevas', 'ano', 'anos', 'dia', 'dias', 'hoy', 'ayer', 'manana', 'vez', 'porque', 'qué', 'dice', 'dicen', 'tiene', 'tienen', 'puede', 'pueden', 'hace', 'hacer', 'ahora', 'directo', 'ultima', 'hora', 'noticias', 'video', 'fotos', 'mismo', 'misma', 'cada', 'bajo', 'durante', 'mediante', 'estan', 'esta', 'sera', 'seran', 'millones', 'mil',
    ];

    /**
     * Spanish (and a few English) 5-character stems mapped to one shared stem, so common
     * news vocabulary lines up across languages: "vuelo" ≈ "flight", "elecciones" ≈ "election".
     */
    private const EQUIVALENTS = [
        // transport / incidents
        'vuelo' => 'fligh', 'avion' => 'plane', 'aeron' => 'plane', 'pasaj' => 'passe', 'apuna' => 'stabb', 'cuchi' => 'knife',
        'ataqu' => 'attac', 'atent' => 'attac', 'yihad' => 'jihad', 'tren' => 'train', 'trene' => 'train',
        'muert' => 'dead', 'muere' => 'dead', 'falle' => 'dead', 'death' => 'dead', 'dies' => 'dead', 'died' => 'dead', 'kille' => 'dead', 'kill' => 'dead',
        'herid' => 'injur', 'deten' => 'arres', 'incen' => 'fire', 'fuego' => 'fire', 'terre' => 'earth', 'inund' => 'flood',
        'lluvi' => 'rain', 'torme' => 'storm', 'hurac' => 'hurri', 'sequi' => 'droug', 'calor' => 'heat',
        // politics / law
        'elecc' => 'elect', 'gobie' => 'gover', 'parla' => 'parli', 'senad' => 'senat', 'guerr' => 'war', 'paz' => 'peace',
        'acuer' => 'deal', 'agree' => 'deal', 'trata' => 'treat', 'front' => 'borde', 'asilo' => 'asylu', 'ley' => 'law', 'leyes' => 'law',
        'juez' => 'judge', 'jueza' => 'judge', 'juece' => 'judge', 'tribu' => 'court', 'juici' => 'trial', 'acusa' => 'accus',
        'prisi' => 'priso', 'carce' => 'priso', 'dimis' => 'resig', 'dimit' => 'resig', 'cumbr' => 'summi', 'reuni' => 'meeti',
        'sanci' => 'sanct', 'aranc' => 'tarif', 'impue' => 'tax', 'taxes' => 'tax', 'misil' => 'missi', 'dron' => 'drone', 'drone' => 'drone',
        'armas' => 'weapo', 'rey' => 'king', 'reina' => 'queen', 'otan' => 'nato',
        // economy / society
        'preci' => 'price', 'vivie' => 'housi', 'alqui' => 'rent', 'huelg' => 'strik', 'empre' => 'compa', 'bolsa' => 'stock',
        'petro' => 'oil', 'escue' => 'schoo', 'vacun' => 'vacci', 'saram' => 'measl',
        // places
        'alema' => 'germa', 'espan' => 'spain', 'marru' => 'moroc', 'japon' => 'japan', 'siria' => 'syria', 'turqu' => 'turke',
        'greci' => 'greec', 'bruse' => 'bruss', 'londr' => 'londo', 'egipt' => 'egypt', 'brasi' => 'brazi', 'ucran' => 'ukrai',
        'rusia' => 'russi', 'liban' => 'leban', 'catar' => 'qatar', 'corea' => 'korea', 'europ' => 'europ',
    ];

    /** @var array<int, array{cluster: int, tokens: list<string>}> */
    private array $docs = [];
    /** @var array<string, list<int>> token → doc ids */
    private array $index = [];
    private bool $loaded = false;
    private float $threshold;

    public function __construct(?float $threshold = null)
    {
        $this->threshold = $threshold ?? (float) config('cluster_threshold', 0.6);
    }

    /** @return list<string> */
    public static function tokens(string $title): array
    {
        static $stop = null;
        $stop ??= array_flip(self::STOPWORDS);
        $out = [];
        foreach (explode(' ', trim(search_text($title))) as $word) {
            if (mb_strlen($word) < 3 || isset($stop[$word]) || ctype_digit($word)) {
                continue;
            }
            $stem = mb_substr($word, 0, 5);
            $out[self::EQUIVALENTS[$stem] ?? $stem] = true;
        }
        return array_keys($out);
    }

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $since = now_utc(-(int) config('cluster_window_hours', 48) * 3600);
        $rows = q("SELECT id, cluster_id, title_tokens FROM articles WHERE published_at >= ? AND title_tokens != ''", [$since]);
        foreach ($rows as $row) {
            $this->add((int) $row['id'], (int) ($row['cluster_id'] ?: $row['id']), explode(' ', $row['title_tokens']));
        }
    }

    /** Find the cluster for a new article (its own id if nothing matches) and remember it. */
    public function assign(int $articleId, array $tokens): int
    {
        $this->load();
        $cluster = $this->bestMatch($tokens) ?? $articleId;
        $this->add($articleId, $cluster, $tokens);
        return $cluster;
    }

    private function bestMatch(array $tokens): ?int
    {
        if (count($tokens) < 2 || !$this->docs) {
            return null;
        }
        $shared = [];
        foreach ($tokens as $t) {
            foreach ($this->index[$t] ?? [] as $docId) {
                $shared[$docId][] = $t;
            }
        }
        $threshold = $this->threshold;
        $weightA = $this->weight($tokens);
        $best = null;
        $bestScore = 0.0;
        foreach ($shared as $docId => $common) {
            if (count($common) < 2) {
                continue;
            }
            $weightB = $this->weight($this->docs[$docId]['tokens']);
            $score = $this->weight($common) / max(0.001, min($weightA, $weightB));
            // Two shared words need a strong overlap; three or more may pass a little lower.
            $needed = count($common) >= 3 ? $threshold - 0.15 : $threshold;
            if ($score >= $needed && $score > $bestScore) {
                $bestScore = $score;
                $best = $this->docs[$docId]['cluster'];
            }
        }
        return $best;
    }

    private function weight(array $tokens): float
    {
        $n = count($this->docs) + 1;
        $sum = 0.0;
        foreach ($tokens as $t) {
            $df = count($this->index[$t] ?? []);
            $sum += log(($n + 1) / ($df + 1)) + 0.1;
        }
        return $sum;
    }

    private function add(int $id, int $cluster, array $tokens): void
    {
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));
        $this->docs[$id] = ['cluster' => $cluster, 'tokens' => $tokens];
        foreach ($tokens as $t) {
            $this->index[$t][] = $id;
        }
    }
}
