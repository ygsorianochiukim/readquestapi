<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeRepository extends Command
{
    protected $signature = 'make:repository {domain} {name}';

    public function handle()
    {
        $domain = ucfirst($this->argument('domain'));
        $name   = ucfirst($this->argument('name'));

        $path = "app/Domain/{$domain}/Repositories";
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = "{$path}/{$name}.php";
        if (file_exists($filePath)) {
            $this->error("Repository {$name} already exists.");
            return;
        }

        file_put_contents($filePath, <<<PHP
    <?php

    namespace App\Domain\\{$domain}\Repositories;

    class {$name}
    {
        //
    }
    PHP
        );

        $this->info("Repository {$name} created at {$filePath}.");
    }

}
