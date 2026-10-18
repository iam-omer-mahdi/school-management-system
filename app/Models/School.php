<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SchoolType;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property SchoolType $type
 * @property string $name
 */
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    protected $fillable = ['type', 'name'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SchoolType::class,
        ];
    }

    /** @return HasMany<Grade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class)->orderBy('number');
    }
}
