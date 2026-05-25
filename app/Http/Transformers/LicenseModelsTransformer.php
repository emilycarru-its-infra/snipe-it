<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\LicenseModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class LicenseModelsTransformer
{
    public function transformLicenseModels(Collection $models, int $total): array
    {
        $array = [];
        foreach ($models as $model) {
            $array[] = $this->transformLicenseModel($model);
        }
        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformLicenseModel(LicenseModel $model): array
    {
        return [
            'id'                   => (int) $model->id,
            'name'                 => e($model->name),
            'type_code'            => e($model->type_code),
            'description'          => e($model->description),
            'icon'                 => e($model->icon),
            'has_seats'            => (bool) $model->has_seats,
            'has_product_key'      => (bool) $model->has_product_key,
            'has_checkout'         => (bool) $model->has_checkout,
            'has_expiration'       => (bool) $model->has_expiration,
            'has_user_email'       => (bool) $model->has_user_email,
            'has_reassignable'     => (bool) $model->has_reassignable,
            'is_subscription'      => (bool) $model->is_subscription,
            'default_seats'        => (int) $model->default_seats,
            'default_reassignable' => (bool) $model->default_reassignable,
            'fieldset_id'          => $model->fieldset_id ? (int) $model->fieldset_id : null,
            'licenses_count'       => (int) $model->licenses()->count(),
            'created_at'           => Helper::getFormattedDateObject($model->created_at, 'datetime'),
            'updated_at'           => Helper::getFormattedDateObject($model->updated_at, 'datetime'),
            'available_actions'    => [
                'update' => Gate::allows('update', LicenseModel::class),
                'delete' => Gate::allows('delete', LicenseModel::class),
            ],
        ];
    }
}
