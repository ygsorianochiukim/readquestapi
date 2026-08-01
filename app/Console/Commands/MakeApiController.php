<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeApiController extends Command
{
    protected $signature = 'make:api-controller {domain} {name}';
    protected $description = 'Create an API controller (v1)';

    public function handle()
    {
        $domain = ucfirst($this->argument('domain'));
        $name   = ucfirst($this->argument('name'));

        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        $path = app_path('Http/Controllers/Api/V1');
        $file = "{$path}/{$name}.php";

        if (File::exists($file)) {
            $this->error("Controller {$name} already exists.");
            return;
        }

        File::ensureDirectoryExists($path);

        File::put($file, <<<PHP
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\\{$domain}\Services\\{$domain}Service;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class {$name} extends Controller
{
    public function __construct(private {$domain}Service \$service) {}

    public function index()
    {
        return ApiResponse::success(
            \$this->service->getAll()
        );
    }

    public function show(\$id)
    {
        return ApiResponse::success(
            \$this->service->getById(\$id)
        );
    }
}
PHP);

        $this->info("✅ API Controller {$name} created successfully.");
    }
}
