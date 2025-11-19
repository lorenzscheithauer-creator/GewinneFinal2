<?php

$baseUrl = 'https://www.12gewinn.de';
$pdo = new PDO('mysql:host=localhost;dbname=gewinne_final2;charset=utf8mb4', 'root', '');

function fetchHtml(string $url): ?DOMDocument
{
    $html = @file_get_contents($url);
    if ($html === false) {
        return null;
    }

    $dom = new DOMDocument();
    if (@$dom->loadHTML($html) === false) {
        return null;
    }

    return $dom;
}

function absoluteUrl(string $base, string $href): string
{
    if (stripos($href, 'http') === 0) {
        return $href;
    }

    return rtrim($base, '/') . '/' . ltrim($href, '/');
}

function getMenu2Links(string $baseUrl): array
{
    $dom = fetchHtml($baseUrl);
    if ($dom === null) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//li[contains(@class,'Menu2')]//a[@href]");

    $links = [];
    foreach ($nodes as $node) {
        $links[] = absoluteUrl($baseUrl, $node->getAttribute('href'));
    }

    return array_values(array_unique($links));
}

function crawlCategoryPage(string $url): array
{
    $dom = fetchHtml($url);
    if ($dom === null) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $items = $xpath->query("//div[contains(@class,'Item')]");

    $results = [];
    foreach ($items as $item) {
        $link = $xpath->query(".//a[(contains(text(),'Gewinnspiel') or contains(translate(text(),'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ','abcdefghijklmnopqrstuvwxyzäöü'),'zum gewinnspiel')) and @href]", $item)->item(0);
        if ($link instanceof DOMElement) {
            $results[] = ['prize_url' => absoluteUrl($url, $link->getAttribute('href'))];
        }
    }

    return $results;
}

function insertLink(PDO $pdo, string $link): void
{
    // Links, die auf 12gewinn.de selbst zeigen, NICHT speichern
    if (strpos($link, 'https://www.12gewinn.de/') === 0) {
        return;
    }

    // Keine doppelten Einträge desselben Links
    $select = $pdo->prepare('SELECT id FROM gewinnspiele WHERE link_zur_webseite = :link LIMIT 1');
    $select->execute([':link' => $link]);
    if ($select->fetch()) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO gewinnspiele (link_zur_webseite) VALUES (:link)');
    $insert->execute([':link' => $link]);
}

$menuLinks = getMenu2Links($baseUrl);

foreach ($menuLinks as $menuUrl) {
    $items = crawlCategoryPage($menuUrl);
    foreach ($items as $item) {
        insertLink($pdo, $item['prize_url']);
    }
}

echo "Fertig\n";
