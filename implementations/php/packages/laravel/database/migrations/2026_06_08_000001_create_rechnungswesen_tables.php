<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Rechnungswesen\Laravel\Schema\SchemaInstaller;

return new class extends Migration {
    public function up(): void
    {
        SchemaInstaller::create(Schema::connection($this->connectionName()));
    }

    public function down(): void
    {
        SchemaInstaller::drop(Schema::connection($this->connectionName()));
    }

    private function connectionName(): ?string
    {
        $connection = config('rechnungswesen.connection');

        return is_string($connection) ? $connection : null;
    }
};
