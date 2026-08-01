<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeDomain extends Command
{
    protected $signature = 'make:domain {name}';
    protected $description = 'Create DDD domain structure with API files';

    public function handle()
    {
        $name = ucfirst($this->argument('name'));

        $paths = [
            "app/Domain/{$name}/Models",
            "app/Domain/{$name}/DTO",
            "app/Domain/{$name}/Repositories",
            "app/Domain/{$name}/Services",
            "app/Domain/{$name}/Policies",
            "app/Http/Controllers/Api/V1",
            "app/Http/Requests",
        ];

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                File::makeDirectory($path, 0755, true);
                $this->info("Created: {$path}");
            }
        }

        $this->createFiles($name);

        $this->info("✅ Domain {$name} created successfully!");
    }

    protected function createFiles(string $name)
    {
        $files = [
            "app/Domain/{$name}/Models/{$name}.php" =>
                $this->modelStub($name),

            "app/Domain/{$name}/DTO/Create{$name}DTO.php" =>
                $this->dtoStub($name),

            "app/Domain/{$name}/Repositories/{$name}Repository.php" =>
                $this->repositoryStub($name),

            "app/Domain/{$name}/Services/{$name}Service.php" =>
                $this->serviceStub($name),

            "app/Domain/{$name}/Policies/{$name}Policy.php" =>
                $this->policyStub($name),

            "app/Http/Controllers/Api/V1/{$name}Controller.php" =>
                $this->controllerStub($name),

            "app/Http/Requests/Create{$name}Request.php" =>
                $this->requestStub($name),
        ];

        foreach ($files as $path => $content) {
            if (!File::exists($path)) {
                File::put($path, $content);
                $this->info("Created file: {$path}");
            }
        }
    }

    // ================= STUBS =================

    protected function modelStub($name)
    {
        return <<<PHP
<?php

namespace App\Domain\\{$name}\Models;

use Illuminate\Database\Eloquent\Model;

class {$name} extends Model
{
    protected \$fillable = [];
}
PHP;
    }

    protected function dtoStub($name)
    {
        return <<<PHP
<?php

namespace App\Domain\\{$name}\DTO;

class Create{$name}DTO
{
    public function __construct(
        // define properties
    ) {}
}
PHP;
    }

    protected function repositoryStub($name)
    {
        return <<<PHP
<?php

namespace App\Domain\\{$name}\Repositories;

class {$name}Repository
{
    //
}
PHP;
    }

    protected function serviceStub($name)
    {
        return <<<PHP
<?php

namespace App\Domain\\{$name}\Services;

class {$name}Service
{
    //
}
PHP;
    }

    protected function policyStub($name)
    {
        return <<<PHP
<?php

namespace App\Domain\\{$name}\Policies;

class {$name}Policy
{
    //
}
PHP;
    }

    protected function controllerStub($name)
    {
        return <<<PHP
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class {$name}Controller extends Controller
{
    //
}
PHP;
    }

    protected function requestStub($name)
    {
        return <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class Create{$name}Request extends FormRequest
{
    public function rules(): array
    {
        return [];
    }
}
PHP;
    }
}
