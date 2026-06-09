<?php

namespace Plugin\TutorialImporter\Services;

use App\Models\Knowledge;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\PluginConfigService;

class TutorialImportService
{
    private string $basePath;
    private string $summaryFile;
    private array $supportedLangs = ['en-US' => 'en-US', 'zh-CN' => 'zh-CN'];
    private ?array $config = null;

    public function __construct()
    {
        $this->basePath = base_path('plugins/TutorialImporter/ppanel-tutorial');
        $this->summaryFile = $this->basePath . '/SUMMARY.md';

        // Load plugin config
        try {
            $configService = app(PluginConfigService::class);
            $fullConfig = $configService->getConfig('tutorial_importer');
            // Transform config format from service (which includes metadata) to key-value pairs
            $this->config = array_map(function($item) {
                return $item['value'];
            }, $fullConfig);
        } catch (\Exception $e) {
            Log::warning('Tutorial Import: Failed to load config: ' . $e->getMessage());
            $this->config = [];
        }
    }

    public function import(): array
    {
        Log::info('Tutorial Import: Starting import...');

        // Check if remote sync is configured
        $this->syncFromRemote();

        if (!File::exists($this->summaryFile)) {
            throw new \Exception("SUMMARY.md not found at {$this->summaryFile}");
        }

        try {
            $content = File::get($this->summaryFile);

            // Strip YAML frontmatter markers (---) if present
            $yamlContent = trim(preg_replace('/^---\s*\n/', '', $content));
            // Remove trailing --- if present (YAML document separator)
            $yamlContent = preg_replace('/\n---\s*$/', '', $yamlContent);

            $summary = Yaml::parse($yamlContent);
            if (!is_array($summary)) {
                throw new \Exception('SUMMARY.md did not parse into a valid array');
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse SUMMARY.md: ' . $e->getMessage());
        }

        Log::info('Tutorial Import: SUMMARY parsed, root keys: ' . implode(', ', array_keys($summary)));

        $results = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($summary as $langKey => $categories) {
            $dbLang = $this->supportedLangs[$langKey] ?? $langKey;
            if (!is_array($categories)) {
                Log::warning("Tutorial Import: Skipping lang '{$langKey}' — categories is not an array");
                continue;
            }

            Log::info("Tutorial Import: Processing lang '{$langKey}' (db: {$dbLang}), " . count($categories) . ' categories');

            foreach ($categories as $categoryData) {
                if (!is_array($categoryData) || !isset($categoryData['title'])) {
                    Log::warning("Tutorial Import: Skipping invalid category entry: " . json_encode($categoryData, JSON_UNESCAPED_UNICODE));
                    continue;
                }
                $categoryName = $categoryData['title'];

                if (isset($categoryData['subItems']) && is_array($categoryData['subItems'])) {
                    foreach ($categoryData['subItems'] as $item) {
                        $results['total']++;
                        try {
                            $this->processItem($item, $dbLang, $categoryName);
                            $results['success']++;
                        } catch (\Exception $e) {
                            $results['failed']++;
                            $results['errors'][] = "Failed to import {$item['title']} ($dbLang): " . $e->getMessage();
                            Log::error("Tutorial Import Error: " . $e->getMessage());
                        }
                    }
                } elseif (isset($categoryData['path'])) {
                     $results['total']++;
                     try {
                         $this->processItem($categoryData, $dbLang, $categoryName);
                         $results['success']++;
                     } catch (\Exception $e) {
                         $results['failed']++;
                         $results['errors'][] = "Failed to import {$categoryData['title']} ($dbLang): " . $e->getMessage();
                     }
                } else {
                    Log::warning("Tutorial Import: Category '{$categoryName}' has neither subItems nor path, skipping");
                }
            }
        }

        Log::info("Tutorial Import: Finished. Total: {$results['total']}, Success: {$results['success']}, Failed: {$results['failed']}");

        return $results;
    }

    private function syncFromRemote(): void
    {
        $repoUrl = $this->config['repository_url'] ?? '';
        if (empty($repoUrl)) {
            Log::info('Tutorial Import: No repository_url configured, using local files.');
            return;
        }

        Log::info("Tutorial Import: repository_url configured: {$repoUrl}");
        $branch = $this->config['branch'] ?? 'main';
        $targetDir = $this->basePath;

        // Check if git is installed
        $result = exec('git --version 2>&1', $output, $returnVar);
        if ($returnVar !== 0 || $result === false) {
            Log::error("Tutorial Import: Git is not available on the server. exec returned: " . ($result === false ? 'false' : $result));
            return;
        }

        // Mark the target directory as safe for git (Docker/container environments)
        exec('git config --global --add safe.directory ' . escapeshellarg($targetDir) . ' 2>/dev/null');

        if (File::exists($targetDir . '/.git')) {
            // Pull changes
            Log::info("Tutorial Import: Pulling changes from $repoUrl ($branch)...");
            $command = "cd " . escapeshellarg($targetDir) . " && git fetch origin 2>&1 && git reset --hard origin/" . escapeshellarg($branch) . " 2>&1";
        } else {
            // Clone repo
            Log::info("Tutorial Import: Cloning $repoUrl ($branch)...");
            // Ensure directory is empty or remove it first if it's not a git repo but exists
            if (File::exists($targetDir)) {
                 File::deleteDirectory($targetDir);
            }
            $command = "git clone -b " . escapeshellarg($branch) . " " . escapeshellarg($repoUrl) . " " . escapeshellarg($targetDir) . " 2>&1";
        }

        Log::info("Tutorial Import: Executing: $command");
        exec($command, $output, $returnVar);

        $outputText = implode("\n", $output);
        Log::info("Tutorial Import: Git output: " . substr($outputText, 0, 1000));

        if ($returnVar !== 0) {
            $errorMsg = !empty($outputText) ? $outputText : "exec returned code {$returnVar}";
            Log::error("Tutorial Import: Git sync failed: $errorMsg");
            throw new \Exception("Git sync failed: $errorMsg");
        }

        Log::info("Tutorial Import: Git sync completed successfully.");
        // Verify the target directory exists after sync
        if (!File::exists($targetDir)) {
            throw new \Exception("Git sync completed but target directory does not exist: {$targetDir}");
        }
    }

    private function processItem(array $item, string $lang, string $category)
    {
        $mdPath = $this->basePath . '/' . $item['path'];
        if (!File::exists($mdPath)) {
            throw new \Exception("File not found: {$mdPath}");
        }

        $content = File::get($mdPath);

        // Extract body (remove frontmatter if exists)
        $body = $content;
        if (Str::startsWith($content, '---')) {
            $parts = preg_split('/^---\s*$/m', $content, 3);
            if (count($parts) === 3) {
                $body = $parts[2];
            }
        }

        // Process images
        $mdDir = dirname($mdPath);
        $body = $this->processImages($body, $mdDir, $lang, $category, $item['title']);

        $knowledge = Knowledge::where('title', $item['title'])
            ->where('language', $lang)
            ->where('category', $category)
            ->first();

        if ($knowledge) {
            $knowledge->update([
                'body' => $body
            ]);
            Log::info("Tutorial Import: Updated article '{$item['title']}' ({$lang}/{$category})");
        } else {
            Knowledge::create([
                'title' => $item['title'],
                'language' => $lang,
                'category' => $category,
                'body' => $body,
                'sort' => 0,
                'show' => true,
            ]);
            Log::info("Tutorial Import: Created article '{$item['title']}' ({$lang}/{$category})");
        }
    }

    public function clearAll(): void
    {
        // 1. Delete all knowledge entries (or just the ones created by this plugin? 
        // User asked to "clear all knowledge base", assuming truncating the table is what they want for "purification")
        Knowledge::truncate();

        // 2. Delete the uploaded images directory
        $storagePath = public_path('upload/knowledge');
        if (File::exists($storagePath)) {
            File::deleteDirectory($storagePath);
        }

        Log::info("Tutorial Import: Knowledge base cleared.");
    }

    private function processImages(string $body, string $mdDir, string $lang, string $category, string $tutorialTitle): string
    {
        return preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($matches) use ($mdDir, $lang, $category, $tutorialTitle) {
            $alt = $matches[1];
            $src = $matches[2];

            // If remote URL, leave as is
            if (Str::startsWith($src, ['http://', 'https://', '//'])) {
                return $matches[0];
            }

            // Local file processing
            $localPath = realpath($mdDir . '/' . $src);
            if ($localPath && File::exists($localPath)) {
                // Use original filename
                $fileName = File::basename($localPath);

                // Define storage path: public/upload/knowledge/{lang}/{category_slug}/{tutorial_slug}/
                // Using sub-folder for each tutorial to avoid filename conflicts
                $categorySlug = Str::slug($category);
                $tutorialSlug = Str::slug($tutorialTitle);
                
                $relativePath = "upload/knowledge/{$lang}/{$categorySlug}/{$tutorialSlug}";
                $storagePath = public_path($relativePath);

                if (!File::exists($storagePath)) {
                    File::makeDirectory($storagePath, 0755, true);
                }
                
                // Copy file (overwrite if exists)
                File::copy($localPath, $storagePath . '/' . $fileName);

                $publicUrl = "/{$relativePath}/{$fileName}";
                return "![$alt]($publicUrl)";
            }

            return $matches[0];
        }, $body);
    }
}
