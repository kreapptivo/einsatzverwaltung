<?php
namespace abrain\Einsatzverwaltung\Types;

use abrain\Einsatzverwaltung\CustomFieldsRepository;

/**
 * Description of the custom post type for units
 * @package abrain\Einsatzverwaltung\Types
 */
class Unit implements CustomPostType
{
    /**
     * @return array
     */
    private function getLabels()
    {
        return array(
            'name' => _x('Units', 'post type general name', 'einsatzverwaltung'),
            'singular_name' => _x('Unit', 'post type singular name', 'einsatzverwaltung'),
            'menu_name' => __('Units', 'einsatzverwaltung'),
            'add_new' => _x('Add New', 'Unit', 'einsatzverwaltung'),
            'add_new_item' => __('Add New Unit', 'einsatzverwaltung'),
            'edit_item' => __('Edit Unit', 'einsatzverwaltung'),
            'new_item' => __('New Unit', 'einsatzverwaltung'),
            'view_item' => __('View Unit', 'einsatzverwaltung'),
            'view_items' => __('View Units', 'einsatzverwaltung'),
            'search_items' => __('Search Units', 'einsatzverwaltung'),
            'not_found' => __('No units found.', 'einsatzverwaltung'),
            'not_found_in_trash' => __('No units found in Trash.', 'einsatzverwaltung'),
            'archives' => __('Unit Archives', 'einsatzverwaltung'),
            'attributes' => __('Unit Attributes', 'einsatzverwaltung'),
            'insert_into_item' => __('Insert into unit', 'einsatzverwaltung'),
            'uploaded_to_this_item' => __('Uploaded to this unit', 'einsatzverwaltung'),
            'featured_image' => _x('Featured Image', 'unit', 'einsatzverwaltung'),
            'set_featured_image' => _x('Set featured image', 'unit', 'einsatzverwaltung'),
            'remove_featured_image' => _x('Remove featured image', 'unit', 'einsatzverwaltung'),
            'use_featured_image' => _x('Use as featured image', 'unit', 'einsatzverwaltung'),
            'filter_items_list' => __('Filter units list', 'einsatzverwaltung'),
            'items_list_navigation' => __('Units list navigation', 'einsatzverwaltung'),
            'items_list' => __('Units list', 'einsatzverwaltung'),
            'item_published' => __('Unit published.', 'einsatzverwaltung'),
            'item_published_privately' => __('Unit published privately.', 'einsatzverwaltung'),
            'item_reverted_to_draft' => __('Unit reverted to draft.', 'einsatzverwaltung'),
            'item_scheduled' => __('Unit scheduled.', 'einsatzverwaltung'),
            'item_updated' => __('Unit updated.', 'einsatzverwaltung'),
        );
    }

    /**
     * @inheritDoc
     */
    public function getRegistrationArgs()
    {
        return array(
            'labels' => $this->getLabels(),
            'public' => false,
            'supports' => array('title', 'editor', 'thumbnail', 'revisions', 'custom-fields', 'author'),
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=' . Report::getSlug(),
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'capability_type' => self::getSlug(),
            'map_meta_cap' => true,
            'menu_icon' => 'dashicons-networking',
        );
    }

    /**
     * @inheritDoc
     */
    public static function getSlug()
    {
        return 'evw_unit';
    }

    /**
     * @inheritDoc
     */
    public function registerCustomFields(CustomFieldsRepository $customFields)
    {
    }

    /**
     * @inheritDoc
     */
    public function registerHooks()
    {
    }
}
