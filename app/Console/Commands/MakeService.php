<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeService extends Command
{
    protected $signature = 'make:service {domain} {name}';
    protected $description = 'Create a Service class inside a domain';

    public function handle()
    {
        $domain = ucfirst($this->argument('domain'));
        $name   = ucfirst($this->argument('name'));

        $path = app_path("Domain/{$domain}/Services");
        $file = "{$path}/{$name}.php";

        if (File::exists($file)) {
            $this->error("Service {$name} already exists.");
            return;
        }

        File::ensureDirectoryExists($path);

        File::put($file, <<<PHP
<?php

namespace App\Domain\\{$domain}\Services;

class {$name}
{
    //
}
PHP);

        $this->info("✅ Service {$name} created successfully.");
    }
}
