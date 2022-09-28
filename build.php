<?php

require __DIR__ . '/vendor/autoload.php';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new StrikethroughExtension());
$converter = new MarkdownConverter($environment);

$sourcePath = '/Users/matt/OneDrive/Apps/Obsidian/WtF2E';
$buildPath = __DIR__ . '/build';
$buildImagePath = $buildPath . '/Attachments';

if (!file_exists($buildPath)) {
    mkdir($buildPath);
}
if (!file_exists($buildImagePath)) {
    mkdir($buildImagePath);
}

$sourceFiles = iterator_to_array(
    new CallbackFilterIterator(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath),
        ),
        fn ($entry) => $entry->isFile() && $entry->getExtension() === 'md',
    )
);

$getLinkName = fn(SplFileInfo $file): string => str_replace('.' . $file->getExtension(), '', $file->getFilename());

$linkMap = [];
foreach ($sourceFiles as $sourceFileInfo) {
    $linkName = $getLinkName($sourceFileInfo);
    $linkPath = ltrim(str_replace($sourcePath, '', $sourceFileInfo->getPath()) . '/' . $linkName . '.html', '/');
    $linkMap[$linkName] = $linkPath;
}

foreach ($sourceFiles as $sourceFileInfo) {
    $linkName = $getLinkName($sourceFileInfo);
    $linkPath = $linkMap[$linkName];
    $relativeLinkBasePath = str_repeat('../', count(explode('/', $linkPath)) - 1);
    $sourceFileContents = file_get_contents($sourceFileInfo->getPathname());
    $buildFileContents = $sourceFileContents;
    if (preg_match_all('/!?\[\[([^\]|#]+)(?:#([^\]|]+))?(?:\|([^\]]+))?\]\]/', $sourceFileContents, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            [ $existingLink, $existingLinkName ] = $match;
            $existingLinkAnchor = isset($match[2]) ? urlencode($match[2]) : null;
            $existingLinkText = $match[3] ?? null;
            if (str_ends_with($existingLinkName, '.png')) {
                copy($sourcePath . '/Attachments/' . $existingLinkName, $buildImagePath . '/' . $existingLinkName);
                $newLink = '<img src="' . $relativeLinkBasePath . 'Attachments/' . $existingLinkName . '">';
            } else {
                $existingLinkPath = $linkMap[$existingLinkName] ?? null;
                if ($existingLinkPath === null) {
                    echo $sourceFileInfo->getPathname() . ' contains bad link ' . $existingLink . PHP_EOL;
                    exit(1);
                }
                $newLinkPath = dirname($linkPath) === dirname($existingLinkPath)
                    ? $existingLinkName . '.html'
                    : $relativeLinkBasePath . $existingLinkPath;
                if (!empty($existingLinkAnchor)) {
                    $newLinkPath .= '#' . $existingLinkAnchor;
                }
                $newLinkText = $existingLinkText ?: $existingLinkName;
                $newLink = '[' . $newLinkText . '](' . str_replace(' ', '%20', $newLinkPath) . ')';
            }
            $buildFileContents = str_replace($existingLink, $newLink, $buildFileContents);
        }
    }
    $buildFileContents = $converter->convert($buildFileContents);
    $buildFileContents = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$linkName</title>
<style type="text/css">
body { margin: 40px auto; max-width: 650px; line-height: 1.6; font-size: 18px; color: #444; padding: 0 10px; }
h1, h2, h3 { line-height: 1.2; }
</style>
</head>
<body>
<main>
$buildFileContents
</main>
</body>
</html>
HTML;
    $buildFilePath = $buildPath . '/' . $linkMap[$linkName];
    echo $buildFilePath . PHP_EOL;
    $buildFileDir = dirname($buildFilePath);
    if (!file_exists($buildFileDir)) {
        mkdir($buildFileDir, recursive: true);
    }
    file_put_contents($buildFilePath, $buildFileContents);
}
