<?php

namespace App\Console\Commands;

use App\Actions\SaveSupportLibrary;
use App\Support\SupportLibraryException;
use Illuminate\Console\Command;

class InitializeSupportLibrary extends Command
{
    protected $signature = 'support-library:initialize';

    protected $description = '仅为空模块初始化经过校验的52构件、30搭配、58组成';

    public function handle(SaveSupportLibrary $writer): int
    {
        try {
            $version = $writer->initialize();
            $this->info('共享支架库初始化完成，版本 '.$version->revision.'。');

            return self::SUCCESS;
        } catch (SupportLibraryException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
