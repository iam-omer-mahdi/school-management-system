<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\FeeTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $system_key
 * @property bool $is_mandatory
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class FeeType extends Model
{
    /** @use HasFactory<FeeTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_mandatory'];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
        ];
    }

    /** @return HasMany<GradeFee, $this> */
    public function gradeFees(): HasMany
    {
        return $this->hasMany(GradeFee::class);
    }

    public function isTuition(): bool
    {
        return $this->system_key === 'tuition';
    }
}
