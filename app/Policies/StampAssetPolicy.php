<?php

namespace App\Policies;

use App\Enums\AssetOwnerScope;
use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Http\Request;

class StampAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user, ?Request $request = null): bool
    {
        $request ??= request();
        $scope = $request?->input('owner_scope');
        $ownerId = $request?->input('owner_id');

        return match ($scope) {
            AssetOwnerScope::Personal->value     => true,
            AssetOwnerScope::Department->value   => $user->employee?->department_id !== null
                                                    && (int) $ownerId === $user->employee->department_id,
            AssetOwnerScope::Organization->value => $user->hasPermission('document_assets.manage'),
            default                              => false,
        };
    }

    public function delete(User $user, StampAsset $asset): bool
    {
        if ($user->hasPermission('document_assets.manage')) {
            return true;
        }
        return $asset->created_by === $user->id;
    }
}
