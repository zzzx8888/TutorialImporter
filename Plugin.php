<?php

namespace Plugin\TutorialImporter;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\TutorialImporter\Services\TutorialImportService;
use Plugin\TutorialImporter\Services\PluginUpdateService;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\PluginConfigService;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 注册后台管理菜单
        $this->listen('admin.menu', function ($menu) {
            $menu[] = [
                'title' => 'Tutorial Import',
                'icon' => 'upload',
                'path' => '/plugin/tutorial-importer',
                'component' => 'plugin.tutorial-importer.index', // 假设前端组件
                // 或者直接是一个按钮操作，这里可能需要根据前端实现来调整
            ];
            return $menu;
        });
    }

    public function install(): void
    {
        // Plugin install logic
    }

    public function schedule(Schedule $schedule): void
    {
        $config = $this->getConfig();

        // Handle Immediate Import — always register the task, check flag inside callback
        $schedule->call(function () {
            $configService = app(PluginConfigService::class);
            $fullConfig = $configService->getDbConfig('tutorial_importer');

            if (!isset($fullConfig['immediate_import']) || $fullConfig['immediate_import'] !== '1') {
                return;
            }

            // Reset flag first to prevent retry loops on failure
            $fullConfig['immediate_import'] = '0';
            $configService->updateConfig('tutorial_importer', $fullConfig);

            try {
                Log::info('Tutorial Importer: Starting immediate import...');
                $service = new TutorialImportService();
                $result = $service->import();
                $fullConfig['import_status'] = "导入成功: 总计{$result['total']}, 成功{$result['success']}, 失败{$result['failed']}";
                $fullConfig['last_import_time'] = now()->toDateTimeString();
                $configService->updateConfig('tutorial_importer', $fullConfig);
                Log::info('Tutorial Importer: Immediate import completed.');
            } catch (\Exception $e) {
                Log::error('Tutorial Importer: Immediate import failed: ' . $e->getMessage());
                $fullConfig['import_status'] = "导入失败: " . $e->getMessage();
                $fullConfig['last_import_time'] = now()->toDateTimeString();
                $configService->updateConfig('tutorial_importer', $fullConfig);
            }
        })->everyMinute();

        // Handle Plugin Update Actions — always register the task, check flag inside callback
        $schedule->call(function () {
            $configService = app(PluginConfigService::class);
            $fullConfig = $configService->getDbConfig('tutorial_importer');
            $action = $fullConfig['update_action'] ?? 'none';

            if ($action === 'none') {
                return;
            }

            // Reset flag first to prevent retry loops on failure
            $fullConfig['update_action'] = 'none';
            $configService->updateConfig('tutorial_importer', $fullConfig);

            try {
                $updateService = new PluginUpdateService();

                if ($action === 'check') {
                    Log::info('Tutorial Importer: Checking for updates...');
                    $result = $updateService->checkUpdate();

                    $fullConfig['update_status'] = $result['message'];
                    $configService->updateConfig('tutorial_importer', $fullConfig);
                    Log::info('Tutorial Importer: Update check completed: ' . $result['message']);

                } elseif ($action === 'update') {
                    Log::info('Tutorial Importer: Starting update process...');
                    $check = $updateService->checkUpdate();
                    if ($check['has_update']) {
                         if ($updateService->performUpdate($check['download_url'])) {
                             $fullConfig['update_status'] = "更新成功！当前版本：" . $check['latest_version'];
                         } else {
                             $fullConfig['update_status'] = "更新失败，请查看日志。";
                         }
                    } else {
                         $fullConfig['update_status'] = "当前已是最新版本，无需更新。";
                    }
                    $configService->updateConfig('tutorial_importer', $fullConfig);
                    Log::info('Tutorial Importer: Update process finished.');
                }

            } catch (\Exception $e) {
                Log::error('Tutorial Importer: Update action failed: ' . $e->getMessage());
                try {
                    $fullConfig['update_status'] = "操作失败：" . $e->getMessage();
                    $configService->updateConfig('tutorial_importer', $fullConfig);
                } catch (\Exception $ex) {}
            }
        })->everyMinute();

        // Handle Knowledge Base Cleanup — always register the task, check flag inside callback
        $schedule->call(function () {
            $configService = app(PluginConfigService::class);
            $fullConfig = $configService->getDbConfig('tutorial_importer');

            if (!isset($fullConfig['cleanup_action']) || $fullConfig['cleanup_action'] !== 'clear') {
                return;
            }

            // Reset flag first to prevent retry loops on failure
            $fullConfig['cleanup_action'] = 'none';
            $configService->updateConfig('tutorial_importer', $fullConfig);

            try {
                Log::info('Tutorial Importer: Starting knowledge base cleanup...');
                $service = new TutorialImportService();
                $service->clearAll();

                $fullConfig['cleanup_status'] = "清理成功：" . now()->toDateTimeString();
                $configService->updateConfig('tutorial_importer', $fullConfig);

                Log::info('Tutorial Importer: Knowledge base cleanup completed.');
            } catch (\Exception $e) {
                Log::error('Tutorial Importer: Cleanup failed: ' . $e->getMessage());
                try {
                    $fullConfig['cleanup_status'] = "清理失败：" . $e->getMessage();
                    $configService->updateConfig('tutorial_importer', $fullConfig);
                } catch (\Exception $ex) {}
            }
        })->everyMinute();

        // Handle Scheduled Sync
        $interval = $config['sync_interval'] ?? 'never';
        if ($interval !== 'never') {
            $task = $schedule->call(function () {
                try {
                    Log::info('Tutorial Importer: Starting scheduled sync...');
                    $service = new TutorialImportService();
                    $service->import();
                    Log::info('Tutorial Importer: Scheduled sync completed.');
                } catch (\Exception $e) {
                    Log::error('Tutorial Importer: Scheduled sync failed: ' . $e->getMessage());
                }
            });

            if ($interval === 'daily') {
                $task->daily()->at('00:00');
            } elseif ($interval === 'weekly') {
                $task->weekly()->mondays()->at('00:00');
            }
        }
    }
}
