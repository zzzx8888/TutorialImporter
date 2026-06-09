<?php

namespace Plugin\TutorialImporter\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use ZipArchive;

class PluginUpdateService
{
    protected string $repo = 'zzzx8888/tutorial-importer';
    protected string $pluginPath;
    protected string $currentVersion;

    public function __construct()
    {
        $this->pluginPath = base_path('plugins/TutorialImporter');
        $this->loadCurrentVersion();
    }

    protected function loadCurrentVersion(): void
    {
        $configFile = $this->pluginPath . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $this->currentVersion = $config['version'] ?? '0.0.0';
        } else {
            $this->currentVersion = '0.0.0';
        }
    }

    /**
     * Check for updates
     * Returns array: [has_update => bool, latest_version => string, download_url => string, message => string]
     */
    public function checkUpdate(): array
    {
        try {
            $url = "https://api.github.com/repos/{$this->repo}/releases/latest";
            $response = Http::timeout(10)->get($url);

            if ($response->failed()) {
                Log::error("Tutorial Importer: Failed to check update. Status: " . $response->status());
                return [
                    'has_update' => false,
                    'message' => '检查失败：API 错误'
                ];
            }

            $data = $response->json();
            $latestVersion = $data['tag_name'] ?? '';
            // Remove 'v' prefix if present for comparison
            $cleanLatest = ltrim($latestVersion, 'v');
            $cleanCurrent = ltrim($this->currentVersion, 'v');

            if (version_compare($cleanLatest, $cleanCurrent, '>')) {
                $downloadUrl = $data['zipball_url'] ?? ''; // or assets
                // Prefer 'zipball_url' (source code) or first asset
                if (!empty($data['assets']) && isset($data['assets'][0]['browser_download_url'])) {
                    $downloadUrl = $data['assets'][0]['browser_download_url'];
                }

                return [
                    'has_update' => true,
                    'latest_version' => $latestVersion,
                    'download_url' => $downloadUrl,
                    'message' => "发现新版本 {$latestVersion}！(当前版本: {$this->currentVersion})"
                ];
            }

            return [
                'has_update' => false,
                'latest_version' => $latestVersion,
                'message' => "当前已是最新版本 ({$this->currentVersion})。"
            ];

        } catch (\Exception $e) {
            Log::error("Tutorial Importer: Update check error: " . $e->getMessage());
            return [
                'has_update' => false,
                'message' => '检查失败：' . $e->getMessage()
            ];
        }
    }

    /**
     * Perform the update
     */
    public function performUpdate(string $downloadUrl): bool
    {
        // Use a temporary directory inside the plugin folder to ensure write permissions
        // as the storage directory structure might be restricted in Docker.
        $tempDir = $this->pluginPath . '/.update_tmp';
        
        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $tempZip = $tempDir . '/update.zip';
        $extractPath = $tempDir . '/extract';

        try {
            Log::info("Tutorial Importer: Downloading update from {$downloadUrl}...");
            
            // 1. Download
            $response = Http::timeout(60)->sink($tempZip)->get($downloadUrl);
            if ($response->failed()) {
                throw new \Exception("Download failed with status " . $response->status());
            }

            // 2. Extract
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }
            File::makeDirectory($extractPath, 0755, true);

            $zip = new ZipArchive;
            if ($zip->open($tempZip) === TRUE) {
                $zip->extractTo($extractPath);
                $zip->close();
            } else {
                throw new \Exception("Failed to open zip file.");
            }

            // 3. Identify root folder (GitHub zips usually have a root folder like 'repo-main')
            $files = File::files($extractPath);
            $directories = File::directories($extractPath);
            
            $sourcePath = $extractPath;
            if (count($files) === 0 && count($directories) === 1) {
                $sourcePath = $directories[0];
            }

            // 4. Copy files to plugin directory
            Log::info("Tutorial Importer: Copying files from {$sourcePath} to {$this->pluginPath}...");
            File::copyDirectory($sourcePath, $this->pluginPath);

            // 5. Cleanup
            File::deleteDirectory($tempDir);

            Log::info("Tutorial Importer: Update completed successfully.");
            return true;

        } catch (\Exception $e) {
            Log::error("Tutorial Importer: Update failed: " . $e->getMessage());
            // Cleanup on fail
            if (File::exists($tempDir)) File::deleteDirectory($tempDir);
            return false;
        }
    }
}
