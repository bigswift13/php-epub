<?php

declare(strict_types=1);

namespace bigswift13\Epub;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class EpubParser
{
    private array $dcElements = [];
    private string $directorySeparator = '/';
    private array $manifest = [];
    /** * Lookup table for O(1) access by href
     * @var array<string, array>
     */
    private array $manifestByHref = [];
    private string $opfDir = '';
    private string $opfFile = '';
    private array $spine = [];
    private array $toc = [];
    private ZipArchive $zipArchive;

    public function __construct(
        private string $filePath,
        private ?string $imageWebRoot = null,
        private ?string $linkWebRoot = null
    ) {
        $this->zipArchive = new ZipArchive();
        $this->fileCheck();
    }

    /**
     * Extract EPUB contents to a directory.
     * * @param string|string[]|null $fileType MimeType filter
     */
    public function extract(string $path, string|array|null $fileType = null, bool $except = false): void
    {
        if (!is_dir($path) || !is_writable($path)) {
            throw new RuntimeException('Invalid or unwritable destination folder!');
        }

        $this->open();

        try {
            $filesToExtract = null;

            if ($fileType !== null) {
                if (is_string($fileType)) {
                    $found = $this->getManifestByType($fileType);
                    if ($found) {
                        $filesToExtract = array_column($found, 'href');
                    }
                } elseif (is_array($fileType)) {
                    $filesToExtract = $fileType; // Assumes array of hrefs
                }
            }

            // Logic for 'except'
            if ($except && $filesToExtract !== null) {
                $allFiles = array_column($this->manifest, 'href');
                $filesToExtract = array_diff($allFiles, $filesToExtract);
            }

            if ($filesToExtract === null) {
                $this->zipArchive->extractTo($path);
            } else {
                // Ensure array keys are reset and valid strings
                $this->zipArchive->extractTo($path, array_values($filesToExtract));
            }

            // Post-process HTML files to fix links
            $xhtmlFiles = array_filter($this->manifest, fn($item) => $item['media-type'] === 'application/xhtml+xml');
            $xhtmlPaths = array_column($xhtmlFiles, 'href');

            $processedPaths = ($filesToExtract !== null)
                ? array_intersect($xhtmlPaths, $filesToExtract)
                : $xhtmlPaths;

            foreach ($processedPaths as $file) {
                $fullPath = $path . $this->directorySeparator . $file;
                // Use OS directory separator for file system operations
                $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);

                $this->replaceExtractFile($fullPath, $file);
            }
        } finally {
            $this->close();
        }
    }

    public function getChapter(string $chapterId): string
    {
        $content = $this->getChapterRaw($chapterId);
        $chapterInfo = $this->manifest[$chapterId] ?? null;

        if (!$chapterInfo) {
            throw new RuntimeException("Chapter ID not found in manifest");
        }

        $chapterHref = $chapterInfo['href'];

        // Normalize newlines
        $content = preg_replace("/\r?\n/", "\n", $content);

        // Extract body content
        if (preg_match('/<body[^>]*?>(.*)<\/body[^>]*?>/is', $content, $match)) {
            $content = trim($match[1]);
        }

        // Remove scripts and styles
        $content = preg_replace([
            '/<script[^>]*?>.*?<\/script[^>]*?>/is',
            '/<style[^>]*?>.*?<\/style[^>]*?>/is'
        ], '', $content);

        // Disable event handlers
        $content = preg_replace_callback(
            '/(\s)(on\w+)(\s*=\s*["\']?[^"\'\s>]*?["\'\s>])/',
            fn($m) => $m[1] . "data-disabled-" . $m[2] . $m[3],
            $content
        );

        // Replace Images (src or xlink:href)
        // Optimization: Use $this->manifestByHref for O(1) lookup
        $content = preg_replace_callback(
            '/(\s(?:xlink:href|src)\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/',
            function ($matches) use ($chapterHref) {
                $relativePath = urldecode($matches[2]);
                $fullImgPath = Util::directoryConcat($chapterHref, $relativePath, true);

                // Check if image exists in manifest using the optimized map
                if (isset($this->manifestByHref[$fullImgPath])) {
                    $webRoot = $this->imageWebRoot ? rtrim($this->imageWebRoot, '/') : '';
                    return $matches[1] . $webRoot . '/' . $fullImgPath . $matches[3];
                }

                return $matches[0]; // Return original if not found
            },
            $content
        );

        // Replace Links
        $content = preg_replace_callback(
            '/(\shref\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/',
            function ($matches) use ($chapterHref) {
                $rawLink = urldecode($matches[2]);
                $fullLinkPath = Util::directoryConcat($chapterHref, $rawLink, true);

                [$pathOnly, $fragment] = str_contains($fullLinkPath, '#')
                    ? explode('#', $fullLinkPath, 2)
                    : [$fullLinkPath, null];

                // Check if the file linked exists in manifest
                $exists = false;
                // We check if the base path exists in our manifest
                foreach ($this->manifestByHref as $href => $data) {
                    // Simple check - in a real world scenario strict equality is usually enough
                    // but sometimes query params might exist? Assuming clean paths for EPUB.
                    if ($href === $pathOnly) {
                        $exists = true;
                        break;
                    }
                }

                if ($exists) {
                    $webRoot = $this->linkWebRoot ? rtrim($this->linkWebRoot, '/') : '';
                    $finalLink = $pathOnly . ($fragment ? '#' . $fragment : '');
                    return $matches[1] . $webRoot . "/" . $finalLink . $matches[3];
                }

                return $matches[0];
            },
            $content
        );

        return $content;
    }

    public function getChapterRaw(string $chapterId): string
    {
        $chapter = $this->manifest[$chapterId] ?? null;

        if (!$chapter) {
            throw new RuntimeException('Chapter not found in manifest');
        }

        if (!in_array($chapter['media-type'], ['application/xhtml+xml', 'image/svg+xml'])) {
            // Warning: strict checking might break loosely spec'd epubs, but beneficial for strict mode
            // Allow 'text/html' just in case
            if ($chapter['media-type'] !== 'text/html') {
                throw new RuntimeException('Invalid mime type for chapter: ' . $chapter['media-type']);
            }
        }

        $this->open();
        try {
            return $this->getFileContentFromZipArchive($chapter['href']);
        } finally {
            $this->close();
        }
    }

    public function getDcItem(?string $item = null): array|string|bool
    {
        if ($item === null) {
            return $this->dcElements;
        }
        return $this->dcElements[$item] ?? false;
    }

    public function getFile(string $fileId): string
    {
        $file = $this->manifest[$fileId] ?? null;
        if (!$file) {
            throw new RuntimeException("File ID not found");
        }

        $this->open();
        try {
            return $this->getFileContentFromZipArchive($file['href']);
        } finally {
            $this->close();
        }
    }

    public function getImage(string $imageId): string
    {
        $image = $this->manifest[$imageId] ?? null;

        if (!$image) {
            throw new RuntimeException("Image ID not found");
        }

        if (!str_starts_with(trim(strtolower($image['media-type'])), "image/")) {
            throw new RuntimeException("Invalid mime type for image");
        }

        $this->open();
        try {
            return $this->getFileContentFromZipArchive($image['href']);
        } finally {
            $this->close();
        }
    }

    public function getManifest(?string $item = null): array|bool
    {
        if ($item === null) {
            return $this->manifest;
        }
        return $this->manifest[$item] ?? false;
    }

    public function getManifestByType(string $pattern): array|bool
    {
        // Check if pattern is a regex (contains delimiters)
        $isRegExp = preg_match('/^\/.*\/[a-z]*$/', $pattern) === 1;

        $ret = array_filter($this->manifest, fn($manifest) => $isRegExp
            ? (bool)preg_match($pattern, $manifest['media-type'])
            : $manifest['media-type'] === $pattern
        );

        return empty($ret) ? false : $ret;
    }

    public function getSpine(): array
    {
        return $this->spine;
    }

    public function getTOC(): array
    {
        return $this->toc;
    }

    /**
     * Parse EPUB structure.
     */
    public function parse(): void
    {
        $this->open();

        try {
            $this->parseOpfLocation();
            $this->parseDcData();
            $this->parseManifest();
            $this->parseSpine();
            $this->parseToc();
        } finally {
            $this->close();
        }
    }

    private function close(): void
    {
        $this->zipArchive->close();
    }

    /**
     * Check if file is a valid EPUB.
     */
    private function fileCheck(): void
    {
        $this->open();

        try {
            $mimetype = $this->getFileContentFromZipArchive('mimetype');
            if (trim(strtolower($mimetype)) !== 'application/epub+zip') {
                throw new RuntimeException('The file is not a valid EPUB (mimetype mismatch).');
            }
        } finally {
            $this->close();
        }
    }

    private function getFileContentFromZipArchive(string $fileName): string
    {
        // Check if file exists in zip
        if ($this->zipArchive->locateName($fileName) === false) {
            throw new RuntimeException("File not found in EPUB: {$fileName}");
        }

        $content = $this->zipArchive->getFromName($fileName);

        if ($content === false) {
            throw new RuntimeException("Error reading file stream from EPUB: {$fileName}");
        }

        return $content;
    }

    private function open(): void
    {
        $status = $this->zipArchive->open($this->filePath);
        if ($status !== true) {
            throw new RuntimeException("Failed opening ebook: " . $status);
        }
    }

    private function parseDcData(): void
    {
        $buf = $this->getFileContentFromZipArchive($this->opfFile);
        $opfContents = new SimpleXMLElement($buf);

        $metadata = $opfContents->metadata->children('dc', true);

        // Improve JSON conversion logic
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->dcElements = array_map(fn($item) => (is_array($item) && empty($item)) ? '' : $item, $data);
    }

    private function parseManifest(): void
    {
        $buf = $this->getFileContentFromZipArchive($this->opfFile);
        $opfContents = new SimpleXMLElement($buf);

        $this->manifest = [];
        $this->manifestByHref = [];

        foreach ($opfContents->manifest->item as $item) {
            $attr = $item->attributes();
            $id = (string)$attr->id;
            $href = urldecode((string)$attr->href);

            // Resolve full path immediately
            $fullPath = Util::directoryConcat($this->opfDir, $href);

            $itemData = [
                'id' => $id,
                'href' => $fullPath,
                'media-type' => (string)$attr->{'media-type'},
            ];

            $this->manifest[$id] = $itemData;
            // Optimizing lookup: Store by Href for faster access in getChapter
            $this->manifestByHref[$fullPath] = $itemData;
        }
    }

    private function parseOpfLocation(): void
    {
        $file = "META-INF" . $this->directorySeparator . "container.xml";
        $buf = $this->getFileContentFromZipArchive($file);

        $opfContents = new SimpleXMLElement($buf);
        // Using strict null checks
        $rootFile = $opfContents->rootfiles->rootfile[0] ?? null;

        if (!$rootFile) {
            throw new RuntimeException('Invalid EPUB: container.xml missing rootfile.');
        }

        $attributes = $rootFile->attributes();
        $this->opfFile = (string)$attributes['full-path'];

        $this->opfDir = dirname($this->opfFile);
        if ($this->opfDir === '.') {
            $this->opfDir = '';
        }
    }

    private function parseSpine(): void
    {
        $buf = $this->getFileContentFromZipArchive($this->opfFile);
        $opfContents = new SimpleXMLElement($buf);

        $this->spine = [];
        foreach ($opfContents->spine->itemref as $item) {
            $this->spine[] = (string)$item->attributes()->idref;
        }
    }

    private function parseToc(): void
    {
        $ncxItem = $this->getManifestByType('application/x-dtbncx+xml');
        // Handle case where NCX might not exist or be different version (EPUB3 uses nav)
        if (!$ncxItem) {
            // Fallback or skip (EPUB 3 usually implies XHTML Navigation Document)
            return;
        }

        // getManifestByType returns array of items, we take the first one
        $ncxItem = reset($ncxItem);

        $buf = $this->getFileContentFromZipArchive($ncxItem['href']);
        $tocContents = new SimpleXMLElement($buf);

        $this->toc = $this->processNavPoints($tocContents->navMap->navPoint);
    }

    private function processNavPoints(SimpleXMLElement $navPoints): array
    {
        $ret = [];
        foreach ($navPoints as $navPoint) {
            $attributes = $navPoint->attributes();
            $srcRaw = (string)$navPoint->content->attributes();
            $src = Util::directoryConcat($this->opfDir, $srcRaw);

            [$fileName, $pageId] = str_contains($src, '#') ? explode('#', $src, 2) : [$src, null];

            $current = [
                'id' => (string)$attributes['id'],
                'name' => (string)$navPoint->navLabel->text,
                'file_name' => $fileName,
                'src' => $src,
                'page_id' => $pageId
            ];

            if (isset($navPoint->navPoint)) {
                $current['children'] = $this->processNavPoints($navPoint->navPoint);
            }
            $ret[] = $current;
        }
        return $ret;
    }

    private function replaceExtractFile(string $realPath, string $fileBasePath): void
    {
        if (!file_exists($realPath) || !is_writable($realPath)) {
            // Depending on requirements, we might want to just log this or ignore
            return;
        }

        $str = file_get_contents($realPath);
        if ($str === false) {
            return;
        }

        // Common callback logic could be extracted to a method to respect DRY,
        // but keeping inline for now as they differ slightly from getChapter.

        // 1. Replace Images
        $str = preg_replace_callback(
            '/(\s(?:xlink:href|src)\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/',
            function ($matches) use ($fileBasePath) {
                $relativePath = urldecode($matches[2]);
                $fullImgPath = Util::directoryConcat($fileBasePath, $relativePath, true);

                if (isset($this->manifestByHref[$fullImgPath])) {
                    $webRoot = $this->imageWebRoot ? rtrim($this->imageWebRoot, '/') : '';
                    return $matches[1] . $webRoot . '/' . $fullImgPath . $matches[3];
                }
                return $matches[0];
            },
            $str
        );

        // 2. Replace Links
        $str = preg_replace_callback(
            '/(\shref\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/',
            function ($matches) use ($fileBasePath) {
                $rawLink = urldecode($matches[2]);
                $fullLinkPath = Util::directoryConcat($fileBasePath, $rawLink, true);

                [$pathOnly, $fragment] = str_contains($fullLinkPath, '#')
                    ? explode('#', $fullLinkPath, 2)
                    : [$fullLinkPath, null];

                if (isset($this->manifestByHref[$pathOnly])) {
                    $webRoot = $this->linkWebRoot ? rtrim($this->linkWebRoot, '/') : '';
                    $finalLink = $pathOnly . ($fragment ? '#' . $fragment : '');
                    return $matches[1] . $webRoot . "/" . $finalLink . $matches[3];
                }
                return $matches[0];
            },
            $str
        );

        file_put_contents($realPath, $str);
    }
}
