<?php
declare(strict_types=1);

namespace Partikulier\Core;

final class ListingPolicy
{
    public function canReadPublic(): bool
    {
        return true;
    }

    public function canCreate(): bool
    {
        return is_user_logged_in() && (current_user_can('edit_posts') || current_user_can('publish_posts'));
    }

    public function canReadPrivate(): bool
    {
        return is_user_logged_in();
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }
}
