## Code: app/Policies/PocketExpenseUploadPolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpenseFileUpload;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;

class PocketExpenseUploadPolicy
{
    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * Determine whether the user can view any pocket expense uploads.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Only authenticated users can view uploads
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Primary Admin has full access to all uploads
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin gets full access only to own uploads by default; needs explicit grant for others
        if ($user->isAdmin()) {
            return true;
        }

        // Business User and Card User can view uploads if they have permission
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the specific pocket expense upload.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $upload
     * @return bool
     */
    public function view(User $user, PocketExpenseFileUpload $upload): bool
    {
        // Only authenticated users can view uploads
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($upload->client_id !== $user->client_id) {
            return false;
        }

        // Primary Admin has full access to all uploads within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Users can view uploads they created
        if ($upload->created_by_user_id === $user->id) {
            return true;
        }

        // Users can view uploads for their own expenses
        if ($upload->user_id === $user->id) {
            return true;
        }

        // Admin can view uploads if they have managing access to the target user
        if ($user->isAdmin()) {
            return $this->canUserManageTargetUser($user, $upload->user_id, $upload->client_id);
        }

        // Business User and Card User can only view uploads they created or for their own expenses
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $upload->created_by_user_id === $user->id || $upload->user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create pocket expense uploads.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Only authenticated users can create uploads
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // All users with OOP expenses permission can create uploads
        return true;
    }

    /**
     * Determine whether the user can update the pocket expense upload.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $upload
     * @return bool
     */
    public function update(User $user, PocketExpenseFileUpload $upload): bool
    {
        // Only authenticated users can update uploads
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($upload->client_id !== $user->client_id) {
            return false;
        }

        // Cannot update completed or processed uploads
        if (in_array($upload->status, ['completed', 'processing'])) {
            return false;
        }

        // Primary Admin has full access to all uploads within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Users can update uploads they created
        if ($upload->created_by_user_id === $user->id) {
            return true;
        }

        // Admin can update uploads if they have managing access to the target user
        if ($user->isAdmin()) {
            return $this->canUserManageTargetUser($user, $upload->user_id, $upload->client_id);
        }

        // Business User and Card User can only update uploads they created
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $upload->created_by_user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the pocket expense upload.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $upload
     * @return bool
     */
    public function delete(User $user, PocketExpenseFileUpload $upload): bool
    {
        // Only authenticated users can delete uploads
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($upload->client_id !== $user->client_id) {
            return false;
        }

        // Cannot delete completed or processing uploads
        if (in_array($upload->status, ['completed', 'processing'])) {
            return false;
        }

        // Primary Admin has full access to all uploads within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Users can delete uploads they created
        if ($upload->created_by_user_id === $user->id) {
            return true;
        }

        // Admin can delete uploads if they have managing access to the target user
        if ($user->isAdmin()) {
            return $this->canUserManageTargetUser($user, $upload->user_id, $upload->client_id);
        }

        // Business User and Card User can only delete uploads they created
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $upload->created_by_user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can upload expenses for a specific target user.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function canUploadForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Only authenticated users can upload for others
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($user->client_id !== $clientId) {
            return false;
        }

        // Primary Admin can upload for any user within the same client
        if ($user->isPrimaryAdmin()) {
            return $this->userBelongsToClient($targetUserId, $clientId);
        }

        // Users can always upload for themselves
        if ($targetUserId === $user->id) {
            return true;
        }

        // Admin can upload for users they manage
        if ($user->isAdmin()) {
            return $this->canUserManageTargetUser($user, $targetUserId, $client