<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

final class NearbyAvailabilityRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxMedicines = (int) config('medifind.discovery.max_medicines');
        $maxPerPage = (int) config('medifind.discovery.max_per_page');
        $maxRadius = (int) config('medifind.discovery.max_radius_meters');

        return [
            'medicine_ids' => ['required', 'array', 'min:1', 'max:'.$maxMedicines],
            'medicine_ids.*' => ['required', 'uuid', 'distinct'],
            'address_id' => ['sometimes', 'uuid'],
            'latitude' => ['sometimes', 'regex:/^-?\d{1,2}(\.\d{1,6})?$|^-?90(\.0{1,6})?$/'],
            'longitude' => ['sometimes', 'regex:/^-?\d{1,3}(\.\d{1,6})?$|^-?180(\.0{1,6})?$/'],
            'radius_meters' => ['sometimes', 'integer', 'min:1', 'max:'.$maxRadius],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $ids = $this->input('medicine_ids');

        if (! is_array($ids)) {
            return;
        }

        $this->merge([
            'medicine_ids' => array_map(
                static fn (mixed $id): mixed => is_string($id) ? strtolower($id) : $id,
                $ids,
            ),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hasAddress = $this->filled('address_id');
            $hasLatitude = $this->exists('latitude');
            $hasLongitude = $this->exists('longitude');

            if ($hasLatitude !== $hasLongitude) {
                $validator->errors()->add('latitude', 'Latitude and longitude must be sent together.');
            }

            $hasPoint = $hasLatitude && $hasLongitude;

            if ($hasAddress === $hasPoint) {
                $validator->errors()->add('address_id', 'Send either a saved address or a latitude and longitude.');
            }

            if ($hasPoint) {
                $latitude = (float) $this->input('latitude');
                $longitude = (float) $this->input('longitude');

                if ($latitude < -90 || $latitude > 90) {
                    $validator->errors()->add('latitude', 'Latitude must be between -90 and 90.');
                }

                if ($longitude < -180 || $longitude > 180) {
                    $validator->errors()->add('longitude', 'Longitude must be between -180 and 180.');
                }
            }

            $page = (int) $this->input('page', 1);
            $perPage = (int) $this->input('per_page', config('medifind.discovery.default_per_page'));
            $maxOffset = (int) config('medifind.discovery.max_offset');

            if (($page - 1) * $perPage >= $maxOffset) {
                $validator->errors()->add('page', 'That page is outside the result window.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public function medicineIds(): array
    {
        $ids = $this->validated('medicine_ids');

        return array_values(array_map(static fn (mixed $id): string => (string) $id, is_array($ids) ? $ids : []));
    }

    public function addressId(): ?string
    {
        $value = $this->validated('address_id');

        return is_string($value) ? $value : null;
    }

    public function latitude(): ?string
    {
        $value = $this->validated('latitude');

        return $value === null ? null : (string) $value;
    }

    public function longitude(): ?string
    {
        $value = $this->validated('longitude');

        return $value === null ? null : (string) $value;
    }

    public function radiusMeters(): int
    {
        $value = $this->input('radius_meters');

        return $value === null
            ? (int) config('medifind.discovery.default_radius_meters')
            : (int) $value;
    }

    public function pageNumber(): int
    {
        return (int) $this->integer('page', 1);
    }

    public function pageSize(): int
    {
        $value = $this->input('per_page');

        return $value === null
            ? (int) config('medifind.discovery.default_per_page')
            : (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['medicine_ids', 'address_id', 'latitude', 'longitude', 'radius_meters', 'page', 'per_page'];
    }
}
