<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Reports the disk usage this container can actually see, with a warning
 * threshold — the same numbers the Settings page's System tab shows.
 *
 * Deliberately scoped to what a container can see on its own: it has no
 * docker.sock and no docker CLI (neither is mounted or installed — giving an
 * application container control over the host's Docker daemon is a much
 * bigger door to open than a disk report is worth), so it cannot enumerate
 * images or run `docker system df` itself. That accounting stays a host-side
 * job — scripts/prune-old-images.sh after every deploy, `docker system df`
 * by hand. What this command reports is everything that grows unbounded
 * from inside the app: the bind-mounted log directory (nothing rotated it
 * until deploy/logrotate/haman-laravel existed), the database backups
 * volume, and the overall free space on the filesystem both live on — the
 * disk actually filled up once already (see DEPLOY.md, 2026-09-21) with no
 * warning before nginx started refusing connections outright.
 */
class DiskReportCommand extends Command
{
    protected $signature = 'haman:disk-report
                            {--warn-percent=85 : Warn when disk usage reaches this percentage}';

    protected $description = 'Report disk usage for logs, backups, and the host filesystem, with a warning threshold';

    public function handle(): int
    {
        $report = self::build((int) $this->option('warn-percent'));

        $this->table(['Path', 'Size'], [
            ['storage/logs (bind-mounted, host-persisted)', $this->formatBytes($report['logs_bytes'])],
            ['storage/app/backups', $this->formatBytes($report['backups_bytes'])],
        ]);

        $this->newLine();
        $this->line(sprintf(
            'Filesystem: %s used of %s (%d%%)',
            $this->formatBytes($report['disk_used']),
            $this->formatBytes($report['disk_total']),
            $report['disk_percent'],
        ));

        if ($report['warning']) {
            $this->warn("WARNING: disk usage is at {$report['disk_percent']}%, at or above the {$report['warn_percent']}% threshold.");
            $this->warn('Run scripts/prune-old-images.sh and `docker system df` on the host — image/build-cache usage is not visible from inside this container.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{logs_bytes: int, backups_bytes: int, disk_total: int, disk_used: int, disk_percent: int, warn_percent: int, warning: bool}
     */
    public static function build(int $warnPercent = 85): array
    {
        $logsBytes = self::directorySize(storage_path('logs'));
        $backupsBytes = self::directorySize(storage_path('app/backups'));

        $total = (int) @disk_total_space('/');
        $free = (int) @disk_free_space('/');
        $used = max(0, $total - $free);
        $percent = $total > 0 ? (int) round(($used / $total) * 100) : 0;

        return [
            'logs_bytes'    => $logsBytes,
            'backups_bytes' => $backupsBytes,
            'disk_total'    => $total,
            'disk_used'     => $used,
            'disk_percent'  => $percent,
            'warn_percent'  => $warnPercent,
            'warning'       => $percent >= $warnPercent,
        ];
    }

    private static function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($items as $item) {
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1) . ' ' . $units[$i];
    }
}
