<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeDomainRequest extends Command
{
    protected $signature = 'make:request {domain} {name}';
    protected $description = 'Create a Form Request for a domain';

    public function handle()
    {
        $domain = ucfirst($this->argument('domain'));
        $name   = ucfirst($this->argument('name'));

        if (!str_ends_with($name, 'Request')) {
            $name .= 'Request';
        }

        $path = app_path('Http/Requests');
        $file = "{$path}/{$name}.php";

        if (File::exists($file)) {
            $this->error("Request {$name} already exists.");
            return;
        }

        File::ensureDirectoryExists($path);

        File::put($file, <<<PHP
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$name} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
PHP);

        $this->info("✅ Request {$name} created successfully.");
    }
}
