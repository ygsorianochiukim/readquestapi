<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeDTO extends Command
{
    protected $signature = 'make:dto {domain} {name}';
    protected $description = 'Create a DTO inside a domain';

    public function handle()
    {
        $domain = ucfirst($this->argument('domain'));
        $name   = ucfirst($this->argument('name'));

        $path = "app/Domain/{$domain}/DTO";

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $file = "{$path}/{$name}.php";

        if (file_exists($file)) {
            $this->error("DTO already exists.");
            return;
        }

        file_put_contents($file, <<<PHP
<?php

namespace App\Domain\\{$domain}\DTO;

class {$name}
{
    public function __construct(
        // properties
    ) {}
}
PHP);

        $this->info("DTO {$name} created successfully.");
    }
}
