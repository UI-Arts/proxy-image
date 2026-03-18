<?php

namespace UIArts\ProxyImage\Console\Commands;

use Illuminate\Console\Command;

class InstallImageFile extends Command
{
    protected $signature = 'proxy-image:install';
    protected $description = 'Copy images.php from the package to public folder';

    public function handle(): int
    {
        $source = __DIR__.'/../../../stubs/images.php';
        $destination = public_path('images.php');

        if (!file_exists($source)) {
            $this->error('Source images.php not found in package.');
            return self::FAILURE;
        }

        if (file_exists($destination)) {
            $this->warn('images.php already exists at '.$destination);

            if (! $this->confirm('Overwrite existing file?')) {
                return self::SUCCESS;
            }
        }

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        if (!copy($source, $destination)) {
            $this->error('Could not copy file to '.$destination);
            return self::FAILURE;
        }

        $this->info('images.php copied to '.$destination);

        return self::SUCCESS;
    }
}
