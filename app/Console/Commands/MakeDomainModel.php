<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeDomainModel extends Command
{
    protected $signature = 'make:domainmodel {domain} {name}';

    public function handle()
    {
        $domain = ucfirst($this->argument('domain'));
        $name   = ucfirst($this->argument('name'));

        $path = "app/Domain/{$domain}/Models";

        // Only create the directory if it doesn't exist
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = "{$path}/{$name}.php";

        // Avoid overwriting existing file
        if (file_exists($filePath)) {
            $this->error("Model {$name} already exists at {$filePath}!");
            return;
        }

        file_put_contents($filePath, <<<PHP
    <?php

    namespace App\Domain\\{$domain}\Models;

    class {$name}
    {
        //
    }
    PHP
    );

        $this->info("Model {$name} created at {$filePath}.");
    }
}
