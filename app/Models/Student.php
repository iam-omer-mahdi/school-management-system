<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $student_number
 * @property string $name
 * @property string $phone
 * @property string|null $secondary_phone
 * @property string|null $home_address
 * @property string|null $mother_name
 * @property string|null $blood_type
 * @property CarbonInterface|null $date_of_birth
 * @property string|null $guardian_name
 * @property string|null $guardian_relationship
 * @property CarbonInterface|null $archived_at
 * @property string|null $archive_note
 */
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    protected $fillable = [
        'student_number',
        'name',
        'phone',
        'secondary_phone',
        'home_address',
        'mother_name',
        'blood_type',
        'date_of_birth',
        'guardian_name',
        'guardian_relationship',
        'archived_at',
        'archive_note',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->orderByDesc('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return HasMany<Enrollment, $this> */
    public function activeEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->whereIn('status', ['active', 'suspended']);
    }

    /** @param Builder<Student> $query */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('student_number', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    /** @param Builder<Student> $query */
    #[Scope]
    protected function withArchiveFilter(Builder $query, ?string $filter): void
    {
        match ($filter) {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };
    }
}
