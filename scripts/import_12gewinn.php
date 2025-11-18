<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$baseUrl = 'https://www.12gewinn.de';
$dbHost  = 'localhost';
$dbName  = 'gewinne_final2';
$dbUser  = 'root';
$dbPass  = '';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/**
 * Lädt eine URL via cURL und gibt ein DOMDocument zurück.
 */
function fetchHtml(string $url): ?DOMDocument
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GewinneFinal2-Bot/1.0)',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ACCEPT_ENCODING => 'gzip,deflate',
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        error_log(sprintf('fetchHtml: Fehler beim Laden von %s (%s)', $url, $error ?: 'HTTP ' . $status));
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if ($dom->loadHTML($response) === false) {
        foreach (libxml_get_errors() as $libError) {
            error_log('DOM Fehler: ' . $libError->message);
        }
        libxml_clear_errors();
        return null;
    }

    libxml_clear_errors();

    return $dom;
}

/**
 * Erzeugt ein DOMXPath-Objekt für ein DOMDocument.
 */
function createXPath(DOMDocument $dom): DOMXPath
{
    return new DOMXPath($dom);
}

/**
 * Wandelt relative URLs in absolute URLs um.
 */
function absoluteUrl(string $baseUrl, string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return $baseUrl;
    }

    if (preg_match('~^https?://~i', $href)) {
        return $href;
    }

    if ($href[0] === '/') {
        return rtrim($baseUrl, '/') . $href;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
}

/**
 * Liefert alle Menu2-Links der Startseite.
 *
 * @return string[]
 */
function getMenu2Links(string $baseUrl): array
{
    $dom = fetchHtml($baseUrl);
    if ($dom === null) {
        return [];
    }

    $xpath = createXPath($dom);
    $nodes = $xpath->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' Menu2 ')]//a[@href]");

    $links = [];
    foreach ($nodes as $node) {
        /** @var DOMElement $node */
        $href = $node->getAttribute('href');
        if ($href === '') {
            continue;
        }
        $links[] = absoluteUrl($baseUrl, $href);
    }

    return array_values(array_unique($links));
}

/**
 * Extrahiert ein Datum aus einem Text.
 */
function extractDateFromText(string $text): ?DateTimeInterface
{
    if (preg_match('/\b(\d{1,2}\.\d{1,2}\.\d{4})\b/', $text, $matches) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!d.m.Y', $matches[1], new DateTimeZone('Europe/Berlin'));
    if ($date === false) {
        return null;
    }

    return $date->setTime(23, 59, 59);
}

/**
 * Crawlt eine Kategorie-Seite und liefert Item-Daten.
 *
 * @return array<int, array{title: ?string, ends_at: ?DateTimeInterface, prize_url: ?string}>
 */
function crawlCategoryPage(string $url): array
{
    $dom = fetchHtml($url);
    if ($dom === null) {
        return [];
    }

    $xpath = createXPath($dom);
    $items = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' Item ')]");

    $results = [];
    foreach ($items as $item) {
        /** @var DOMElement $item */
        $titleNode = $xpath->query('.//h2[1]', $item)->item(0);
        $title = $titleNode instanceof DOMNode ? trim($titleNode->textContent) : null;

        $prizeUrl = null;
        $linkNode = $xpath->query(".//p[contains(concat(' ', normalize-space(@class), ' '), ' DivRechtsButton ')]//a[@href]", $item)->item(0);
        if (!$linkNode) {
            $linkCandidates = $xpath->query(".//a[contains(translate(normalize-space(text()), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'zum gewinnspiel') and @href]", $item);
            if ($linkCandidates !== false && $linkCandidates->length > 0) {
                $linkNode = $linkCandidates->item(0);
            }
        }
        if ($linkNode instanceof DOMElement) {
            $prizeUrl = absoluteUrl($url, $linkNode->getAttribute('href'));
        }

        $dateNode = $xpath->query(".//p[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'einsendeschluss') or contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'teilnahmeschluss')]", $item)->item(0);
        $date = $dateNode instanceof DOMNode ? extractDateFromText($dateNode->textContent) : null;

        $results[] = [
            'title'     => $title !== '' ? $title : null,
            'ends_at'   => $date,
            'prize_url' => $prizeUrl,
        ];
    }

    return $results;
}

/**
 * Fügt ein Gewinnspiel in die Datenbank ein, sofern der Link noch nicht existiert.
 */
function insertGewinnspiel(PDO $pdo, string $link, ?string $beschreibung, ?DateTimeInterface $endsAt): void
{
    $stmt = $pdo->prepare('SELECT id FROM gewinnspiele WHERE link_zur_webseite = :link LIMIT 1');
    $stmt->execute([':link' => $link]);
    if ($stmt->fetch()) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite, beschreibung, status, endet_am) VALUES (:link, :beschreibung, :status, :endet_am)');
    $insert->execute([
        ':link'         => $link,
        ':beschreibung' => $beschreibung,
        ':status'       => 'geplant',
        ':endet_am'     => $endsAt ? $endsAt->format('Y-m-d H:i:s') : null,
    ]);
}

$outputBuffer = '';
$menuLinks = getMenu2Links($baseUrl);

foreach ($menuLinks as $menuUrl) {
    $line = "Crawle Kategorie-Seite: {$menuUrl}\n";
    echoOutput($line);

    $items = crawlCategoryPage($menuUrl);
    foreach ($items as $item) {
        $link = $item['prize_url'] ?? null;
        $beschreibung = $item['title'] ?? null;
        $endsAt = $item['ends_at'] ?? null;

        if ($link === null) {
            continue;
        }

        insertGewinnspiel($pdo, $link, $beschreibung, $endsAt);
        $line = sprintf(
            "Gespeichert: %s%s\n",
            $link,
            $endsAt ? ' (Einsendeschluss: ' . $endsAt->format('Y-m-d') . ')' : ''
        );
        echoOutput($line);
    }
}

echoOutput("Import von 12gewinn.de abgeschlossen.\n");

if (php_sapi_name() !== 'cli') {
    echo '<pre>' . htmlspecialchars($outputBuffer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
}

/**
 * Gibt Text je nach SAPI direkt oder in den Buffer aus.
 */
function echoOutput(string $message): void
{
    global $outputBuffer;
    if (php_sapi_name() === 'cli') {
        echo $message;
        return;
    }

    $outputBuffer .= $message;
}
