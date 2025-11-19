#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Basis-URL der Seite
$baseUrl = 'https://www.12gewinn.de';

// DB-Verbindung (MySQL / MariaDB)
$dbHost  = 'localhost';
$dbName  = 'gewinne_final2';
$dbUser  = 'root';
$dbPass  = '';

// ggf. anpassen, wenn auf dem Linux-Server andere Zugangsdaten genutzt werden

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/**
 * Lädt HTML einer URL und gibt ein DOMDocument zurück (oder null bei Fehler).
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
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        // Für Debug-Zwecke auf dem Server kannst du hier loggen
        // error_log(sprintf('fetchHtml: Fehler beim Laden von %s (%s)', $url, $error ?: 'HTTP ' . $status));
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if ($dom->loadHTML($response) === false) {
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
 * Wandelt relative Links in absolute URLs um.
 */
function absoluteUrl(string $baseUrl, string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return $baseUrl;
    }

    // Bereits absolute URL?
    if (preg_match('~^https?://~i', $href)) {
        return $href;
    }

    // Root-relative URLs
    if ($href[0] === '/') {
        // Domain-Teil aus baseUrl extrahieren
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host']   ?? '';
        return $scheme . '://' . $host . $href;
    }

    // Sonstige relative URLs – ans Ende der Base-URL anhängen
    return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
}

/**
 * Holt alle Menu2-Links von der Startseite.
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

    if ($nodes === false) {
        return [];
    }

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
 * Crawlt eine Kategorie-Seite (Menu2-Link) und liefert die Gewinnspiel-Links.
 *
 * @return array<int, array{prize_url: string}>
 */
function crawlCategoryPage(string $url): array
{
    $dom = fetchHtml($url);
    if ($dom === null) {
        return [];
    }

    $xpath = createXPath($dom);
    $items = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' Item ')]");
    if ($items === false) {
        return [];
    }

    $results = [];
    foreach ($items as $item) {
        /** @var DOMElement $item */
        // Primär: Link im DivRechtsButton
        $linkNode = $xpath->query(".//p[contains(concat(' ', normalize-space(@class), ' '), ' DivRechtsButton ')]//a[@href]", $item)->item(0);

        // Fallback: Link mit Text à la "Zum Gewinnspiel"
        if (!$linkNode) {
            $fallback = $xpath->query(
                ".//a[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü'), 'zum gewinnspiel') and @href]",
                $item
            );
            if ($fallback !== false && $fallback->length > 0) {
                $linkNode = $fallback->item(0);
            }
        }

        if (!$linkNode instanceof DOMElement) {
            continue;
        }

        $href = $linkNode->getAttribute('href');
        if ($href === '') {
            continue;
        }

        $prizeUrl = absoluteUrl($url, $href);
        $results[] = ['prize_url' => $prizeUrl];
    }

    return $results;
}

/**
 * Speichert einen Link in der DB, wenn:
 * - er NICHT mit https://www.12gewinn.de/ beginnt
 * - er noch nicht existiert.
 */
function insertLink(PDO $pdo, string $link): void
{
    // 1. Links, die auf 12gewinn.de selbst zeigen, NICHT speichern
    if (strpos($link, 'https://www.12gewinn.de/') === 0) {
        return;
    }

    // 2. Keine doppelten Einträge desselben Links
    $select = $pdo->prepare('SELECT id FROM gewinnspiele WHERE link_zur_webseite = :link LIMIT 1');
    $select->execute([':link' => $link]);
    if ($select->fetch()) {
        // Link schon vorhanden – nichts tun
        return;
    }

    // 3. Insert
    $insert = $pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite, status) VALUES (:link, :status)');
    $insert->execute([
        ':link'   => $link,
        ':status' => 'geplant',
    ]);
}

// ---------------- Hauptlogik ----------------

$menuLinks = getMenu2Links($baseUrl);

foreach ($menuLinks as $menuUrl) {
    // Optional: Ausgabe für CLI-Logging
    echo "Kategorie: {$menuUrl}\n";

    $items = crawlCategoryPage($menuUrl);

    foreach ($items as $item) {
        $link = $item['prize_url'] ?? null;
        if (!$link) {
            continue;
        }

        insertLink($pdo, $link);
        echo " -> Link gespeichert (falls neu & extern): {$link}\n";
    }
}

echo "Fertig.\n";
