<?php
/**
 * Plugin Name: NG Comments Not Replied To
 * Author: Ashley Gibson
 * Description: Allows you to view comments that you have not yet replied to.
 * Version: 1.0.0
 */

define('NG_WONT_REPLY_META_KEY', '_ng_wont_reply');

/**
 * Modifies a `wp_comments` query to only show comments that haven't been replied to.
 *
 * @param array|mixed $clauses
 * @param $query
 *
 * @return array|mixed
 */
function ng_not_replied_comments_clauses($clauses, $query) {
    global $wpdb;

    $adminUserIds = array_unique(array_map('intval', array_merge(
        [get_current_user_id()],
        get_users(['role' => 'administrator', 'fields' => 'ID'])
    )));

    $adminUserIdsPlaceholderString = implode(', ', array_fill(0, count($adminUserIds), '%d'));
    $adminUserIdsString = implode(',', $adminUserIds);

    $clauses['where'] .= $wpdb->prepare(
        " AND {$wpdb->comments}.comment_parent = 0 AND {$wpdb->comments}.comment_type = 'comment'
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->comments} AS reply
                  WHERE reply.comment_parent = {$wpdb->comments}.comment_ID
                  AND reply.user_id IN ({$adminUserIdsPlaceholderString})
                  AND reply.comment_approved NOT IN ('spam', 'trash')
              )",
        $adminUserIds
    );

    // Exclude admins' own top-level comments
    $clauses['where'] .= " AND {$wpdb->comments}.user_id NOT IN (".$adminUserIdsString.")";

    // Exclude comments marked as "Won't Reply"
    $clauses['where'] .= $wpdb->prepare(
        " AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->commentmeta}
            WHERE {$wpdb->commentmeta}.comment_id = {$wpdb->comments}.comment_ID
            AND {$wpdb->commentmeta}.meta_key = %s
        )",
        NG_WONT_REPLY_META_KEY
    );

    return $clauses;
}

/**
 * Add a count for "Not Replied To" statuses.
 */
add_filter('comment_status_links', function($statusLinks) {
    if (! current_user_can('edit_posts')) {
        return $statusLinks;
    }

    add_filter('comments_clauses', 'ng_not_replied_comments_clauses', 10, 2);
    $count = get_comments(['count' => true, 'status' => 'all']);
    remove_filter('comments_clauses', 'ng_not_replied_comments_clauses', 10);

    $class = ! empty($_GET['ng_not_replied']) ? ' class="current"' : '';

    $statusLinks['not_replied'] = sprintf(
        '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
        esc_url(admin_url('edit-comments.php?comment_status=all&ng_not_replied=1')),
        $class,
        esc_html__('Not Replied To', 'ng-comments-not-replied-to'),
        $count
    );

    return $statusLinks;
});

/**
 * Filter the comment query when on our "Not Replied To" filter view.
 */
add_filter('comments_list_table_query_args', function($args) {
    if (empty($_GET['ng_not_replied']) || ! current_user_can('edit_posts')) {
        return $args;
    }

    add_filter('comments_clauses', 'ng_not_replied_comments_clauses', 10, 2);

    return $args;
});

/**
 * Add hover actions for "Won't Reply" and undo.
 */
add_filter('comment_row_actions', function($actions, $comment) {
    if (! current_user_can('edit_comment', $comment->comment_ID)) {
        return $actions;
    }

    $isWontReply = (bool) get_comment_meta($comment->comment_ID, NG_WONT_REPLY_META_KEY, true);

    $actions['ng_wont_reply'] = sprintf(
        '<a href="#" class="ng-wont-reply" data-comment-id="%d" data-wont-reply-action="%s">%s</a>',
        esc_attr($comment->comment_ID),
        $isWontReply ? 'remove' : 'add',
        $isWontReply
            ? esc_html__("Undo Won't Reply", 'ng-comments-not-replied-to')
            : esc_html__("Won't Reply", 'ng-comments-not-replied-to')
    );

    return $actions;
}, 10, 2);

/**
 * Set up JavaScript variables.
 */
add_action('admin_enqueue_scripts', function($hookSuffix) {
    if ($hookSuffix !== 'edit-comments.php') {
        return;
    }

    wp_localize_script('jquery', 'ngCommentsNotRepliedTo', [
        'nonce'         => wp_create_nonce('ng_wont_reply'),
        'wontReply'     => __("Won't Reply", 'ng-comments-not-replied-to'),
        'undoWontReply' => __("Undo Won't Reply", 'ng-comments-not-replied-to'),
    ]);
});

/**
 * Load the inline JS.
 */
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if (! $screen || $screen->id !== 'edit-comments') {
        return;
    }
    ?>
    <script>
    jQuery(function ($) {
        $('#the-comment-list').on('click', '.ng-wont-reply', function (e) {
            e.preventDefault();

            const $link  = $(this);
            const toggle = $link.data('wont-reply-action');

            $.post(ajaxurl, {
                action:     'ng_toggle_wont_reply',
                comment_id: $link.data('comment-id'),
                toggle:     toggle,
                nonce:      ngCommentsNotRepliedTo.nonce,
            }, function (response) {
                if (! response.success) {
                    return;
                }

                if (toggle === 'add') {
                    $link.data('wont-reply-action', 'remove').text(ngCommentsNotRepliedTo.undoWontReply);

                    if (new URLSearchParams(window.location.search).get('ng_not_replied')) {
                        $link.closest('tr').fadeOut();
                    }
                } else {
                    $link.data('wont-reply-action', 'add').text(ngCommentsNotRepliedTo.wontReply);
                }
            });
        });
    });
    </script>
    <?php
});

/**
 * Ajax callback.
 */
add_action('wp_ajax_ng_toggle_wont_reply', function() {
    $commentId = intval($_POST['comment_id'] ?? 0);
    $toggle    = sanitize_key($_POST['toggle'] ?? '');

    if (! check_ajax_referer('ng_wont_reply', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce.'], 403);
    }

    if (! in_array($toggle, ['add', 'remove'], true)) {
        wp_send_json_error(['message' => 'Invalid action.'], 400);
    }

    if (! current_user_can('edit_comment', $commentId)) {
        wp_send_json_error(['message' => 'Unauthorized.'], 403);
    }

    if ($toggle === 'add') {
        update_comment_meta($commentId, NG_WONT_REPLY_META_KEY, 1);
    } else {
        delete_comment_meta($commentId, NG_WONT_REPLY_META_KEY);
    }

    wp_send_json_success();
});

/**
 * Register the "Mark as Won't Reply" bulk action.
 */
add_filter('bulk_actions-edit-comments', function($bulkActions) {
    $bulkActions['ng_wont_reply'] = __("Mark as Won't Reply", 'ng-comments-not-replied-to');
    return $bulkActions;
});

/**
 * Handle the "Mark as Won't Reply" bulk action.
 */
add_filter('handle_bulk_actions-edit-comments', function($sendback, $doaction, $commentIds) {
    if ($doaction !== 'ng_wont_reply') {
        return $sendback;
    }

    $updated = 0;
    foreach ($commentIds as $commentId) {
        $commentId = intval($commentId);
        if (! current_user_can('edit_comment', $commentId)) {
            continue;
        }
        update_comment_meta($commentId, NG_WONT_REPLY_META_KEY, 1);
        $updated++;
    }

    return add_query_arg('ng_wont_reply_marked', $updated, $sendback);
}, 10, 3);

/**
 * Show a confirmation notice after the bulk action.
 */
add_action('admin_notices', function() {
    if (empty($_GET['ng_wont_reply_marked'])) {
        return;
    }

    $count = intval($_GET['ng_wont_reply_marked']);

    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html(sprintf(
            _n(
                "%d comment marked as Won't Reply.",
                "%d comments marked as Won't Reply.",
                $count,
                'ng-comments-not-replied-to'
            ),
            $count
        ))
    );
});
