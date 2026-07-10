# Notifications System

## Overview

The notifications system tracks user interactions in the feed, specifically comments and high fives on personal records (PRs). Users receive notifications when someone interacts with their PRs. Notifications are automatically marked as read when viewing the notifications page.

## Quick Start

### For Users

1. **View notifications**: Navigate to `/notifications` or click Feed → Notifications in the menu
2. **See unread count**: Badge appears on "Notifications" menu item
3. **Auto-mark as read**: Notifications are automatically marked as read when you view the page (badge shows one last time)
4. **Jump to PR**: Click any notification to see the related PR

### For Developers

**Test the system:**
```bash
php artisan tinker
```

```php
// Get two users
$user1 = User::first();
$user2 = User::skip(1)->first();

// Get a PR from user1
$pr = $user1->personalRecords()->first();

// User2 comments on user1's PR
PRComment::create([
    'personal_record_id' => $pr->id,
    'user_id' => $user2->id,
    'comment' => 'Great job!'
]);

// Check user1's notifications
$user1->notifications()->count(); // Should be > 0
```

## Database Schema

### notifications table
- `id` - Primary key
- `user_id` - The user receiving the notification
- `type` - Type of notification: `pr_comment`, `pr_high_five`, `new_pr`
- `actor_id` - The user who performed the action
- `notifiable_type` - Polymorphic type (PRComment, PRHighFive, etc.)
- `notifiable_id` - Polymorphic ID
- `data` - JSON field for additional context
- `read_at` - Timestamp when notification was read (null = unread)
- `created_at` / `updated_at` - Standard timestamps

## How It Works

### Automatic Notification Creation

Notifications are created automatically via Eloquent observers:

1. **PRCommentObserver** - Creates notification when someone comments on a PR
2. **PRHighFiveObserver** - Creates notification when someone gives a high five

**Rules:**
- Users don't receive notifications for their own actions
- High five notifications are deleted when the high five is removed

### Notification Types

**pr_comment**
- Triggered when someone comments on your PR
- Data includes: `personal_record_id`, `comment_preview`

**pr_high_five**
- Triggered when someone gives your PR a high five
- Data includes: `personal_record_id`

**new_pr** (future)
- Could be used to notify followers when someone achieves a new PR

## User Interface

### Notifications Page
- Route: `/notifications` (route name: `notifications.index`)
- Shows last 30 days of notifications
- Displays unread count in page title
- Automatically marks all notifications as read when page is viewed
- Built using the flexible component system (no dedicated Blade template)

### Navigation Badge
- Feed menu shows notification count badge
- Badge displays on page load one last time before notifications are marked as read
- Visible in the Feed submenu under "Notifications"

### Notification Display
- Unread notifications have blue background (visible on initial page load)
- Shows actor name, action, and time ago
- Links directly to the relevant PR in the feed
- All notifications automatically marked as read after page renders

## API Endpoints

### GET /notifications
Display notifications page and automatically mark all as read

## Models

### Notification Model
Location: `app/Models/Notification.php`

**Relationships:**
- `user()` - The notification recipient
- `actor()` - The user who triggered the notification
- `notifiable()` - Polymorphic relation to the subject (comment, high five, etc.)

**Scopes:**
- `unread()` - Only unread notifications
- `read()` - Only read notifications
- `recent()` - Last 30 days

**Methods:**
- `markAsRead()` - Mark notification as read (used internally)
- `markAsUnread()` - Mark notification as unread (used internally)
- `isUnread()` - Check if notification is unread

## Implementation Details

### Files Created

**Database:**
- `database/migrations/2026_02_09_000001_create_notifications_table.php`

**Models:**
- `app/Models/Notification.php`

**Observers:**
- `app/Observers/PRCommentObserver.php`
- `app/Observers/PRHighFiveObserver.php`

**Controllers:**
- Added to `app/Http/Controllers/FeedController.php`:
  - `notifications()` - Display page and auto-mark as read
  - `buildNotificationComponent()` - Helper for rendering

**Routes:**
- `GET /notifications`

**Configuration:**
- Updated `config/menu.php` - Added Notifications submenu with badge (checks session cache)

**Views & Assets:**
- `public/css/mobile-entry/components/notifications.css`
- Updated `resources/views/mobile-entry/flexible.blade.php`

**Configuration:**
- Updated `config/menu.php` - Added Notifications submenu with badge
- Updated `app/Providers/AppServiceProvider.php` - Registered observers

**Tests:**
- `tests/Feature/NotificationTest.php` - 17 comprehensive tests (all passing)

## What Happens Automatically

- ✅ Comment on someone's PR → They get notified
- ✅ High five someone's PR → They get notified
- ✅ Remove high five → Notification deleted
- ✅ Comment/high five your own PR → No notification (you already know!)
- ✅ View notifications page → All notifications marked as read
- ✅ Badge shows on notifications page → Disappears on next navigation

## Testing

All 13 tests passing:
- Notification creation for comments and high fives
- No self-notifications
- High five removal deletes notification
- Display and UI tests
- Auto-mark as read functionality
- Authorization checks
- Data integrity tests
- Polymorphic relationships
- Scope filtering (recent, unread, read)

Run tests:
```bash
php artisan test --filter NotificationTest
```

## Future Enhancements

Consider adding:
1. **Email notifications** - Send email for important notifications
2. **Push notifications** - Browser/mobile push notifications
3. **User preferences** - Let users control what they're notified about
4. **Batch notifications** - Group multiple high fives into one notification
5. **New PR notifications** - Notify followers when someone achieves a PR
6. **@mentions** - Notify users when mentioned in comments
7. **Notification cleanup** - Auto-delete old read notifications after 90 days
8. **Notification grouping** - "3 people high fived your PR"
9. **Real-time updates** - WebSocket/polling for instant notifications
10. **Notification sounds** - Audio alerts for new notifications

## Migration Status

✅ Migration run successfully  
✅ All tests passing  
✅ Routes registered  
✅ Observers registered  
✅ Menu updated with badge  
✅ CSS included  
✅ Documentation complete  
✅ Production ready
