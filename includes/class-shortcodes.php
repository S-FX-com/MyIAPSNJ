<?php
/**
 * The shortcodes this plugin provides, for the Settings → Shortcodes screen.
 *
 * Add an entry here when a new shortcode is registered. The screen checks
 * each tag with shortcode_exists(), so an entry whose shortcode is not
 * registered shows as such instead of silently describing nothing.
 *
 * @package MyIAPSNJ
 */

defined( 'ABSPATH' ) || exit;

final class My_IAPSNJ_Shortcodes {

    /**
     * Shortcode tag => description. 'atts' rows are [ name, default, what it does ];
     * 'examples' are ready-to-paste uses (the first is the plain tag).
     *
     * @return array<string,array{title:string,summary:string,audience:string,page:string,atts:array<int,array{0:string,1:string,2:string}>,examples:string[]}>
     */
    public static function catalog(): array {
        return [
            My_IAPSNJ_My_Membership::SHORTCODE => [
                'title'    => __( 'My Membership', 'my-iapsnj' ),
                'summary'  => __( 'A horizontal bar, full width of its column, with the member\'s Membership Role (the CRM member type, plus an Administrator / Editor / other staff WordPress role when the account has one), Member Since, Expiration Date, Active / Inactive status and Member Number, read from their FluentCRM contact. Lifetime members read LIFETIME (Honorary: NO EXPIRATION). A Renew Now button appears when the membership has lapsed, or from the renewal season start date set in Dues → Membership Products (Renewal season starts) for a member whose membership runs out before a payment then would; never for Lifetime or Honorary.', 'my-iapsnj' ),
                'audience' => __( 'Logged-in members. Visitors see a log-in link.', 'my-iapsnj' ),
                'page'     => __( 'Membership Portal', 'my-iapsnj' ),
                'atts'     => [
                    [ 'button', __( 'Renew Now', 'my-iapsnj' ), __( 'Text of the renewal button.', 'my-iapsnj' ) ],
                    [ 'regular_url', '', __( 'Renewal checkout link for Regular members. Empty = the renewal product in Dues → Membership Products.', 'my-iapsnj' ) ],
                    [ 'associate_url', '', __( 'Renewal checkout link for Associate members. Empty = the renewal product in Dues → Membership Products.', 'my-iapsnj' ) ],
                ],
                'examples' => [
                    '[' . My_IAPSNJ_My_Membership::SHORTCODE . ']',
                    '[' . My_IAPSNJ_My_Membership::SHORTCODE . ' regular_url="https://example.com/?fluent-cart=instant_checkout&item_id=1&quantity=1" associate_url="https://example.com/?fluent-cart=instant_checkout&item_id=2&quantity=1"]',
                ],
            ],
            My_IAPSNJ_Directory::SHORTCODE => [
                'title'    => __( 'Member Directory', 'my-iapsnj' ),
                'summary'  => __( 'A directory of active members, as cards: headshot (the photo the member uploaded on Edit Profile; a silhouette when there is none), name, member type, member since, department, rank, and email / phone icons that open the mail or phone app. A search box finds members by name as you type (every word must be in the first or last name; two characters or more) and updates the list in place. Filter by membership type (Regular, Associate, Lifetime …), department, rank, join date range and retired (Yes / No); results are paged. Retired means a retirement date on the contact or a Lifetime membership. Only administrators and members with an active membership can see it, because the cards carry email addresses and phone numbers.', 'my-iapsnj' ),
                'audience' => __( 'Logged-in members with an active membership, and administrators. Everyone else sees a message.', 'my-iapsnj' ),
                'page'     => __( 'Membership Directory', 'my-iapsnj' ),
                'atts'     => [
                    [ 'per_page', (string) My_IAPSNJ_Directory::PER_PAGE_DEFAULT, __( 'Members per page (1–100).', 'my-iapsnj' ) ],
                    [ 'photo_meta', 'headshot', __( 'Fallback only: name of a user-meta field holding a headshot (an image address or a Media Library attachment id), used for members who have not uploaded a photo on Edit Profile. Members with neither show the silhouette. Developers can also use the my_iapsnj/directory_photo_url filter.', 'my-iapsnj' ) ],
                ],
                'examples' => [
                    '[' . My_IAPSNJ_Directory::SHORTCODE . ']',
                    '[' . My_IAPSNJ_Directory::SHORTCODE . ' per_page="48" photo_meta="profile_photo"]',
                ],
            ],
            My_IAPSNJ_Profile::SHORTCODE => [
                'title'    => __( 'Edit Profile', 'my-iapsnj' ),
                'summary'  => __( 'The member edits their own FluentCRM contact: name, cell phone, address and the fields of the Checkout Builder form for their level, with the same labels, dropdown lists and rules as the checkout. Every field is filled with what is on file; State is a dropdown of full US state names, and Department is the contact\'s primary company (FluentCRM Companies). The member can also upload a photo: it is cropped to a square, shrunk and compressed, saved in the Media Library and written to the contact\'s photo (FluentCRM avatar), and it shows in the member directory. Email and membership state (type, paid through, member number, join date) cannot be changed here.', 'my-iapsnj' ),
                'audience' => __( 'Logged-in members whose account is linked to a CRM contact.', 'my-iapsnj' ),
                'page'     => __( 'Edit Profile', 'my-iapsnj' ),
                'atts'     => [
                    [ 'button', __( 'Save Changes', 'my-iapsnj' ), __( 'Text of the save button.', 'my-iapsnj' ) ],
                ],
                'examples' => [ '[' . My_IAPSNJ_Profile::SHORTCODE . ']' ],
            ],
            My_IAPSNJ_Change_Password::SHORTCODE => [
                'title'    => __( 'Change Password', 'my-iapsnj' ),
                'summary'  => sprintf(
                    /* translators: %d: minimum password length */
                    __( 'Change My Password form: the new password typed twice, a strength meter and a show / hide eye on each field. Needs at least %d characters and a password different from the current one. The member stays signed in on this device and WordPress sends its "password changed" email.', 'my-iapsnj' ),
                    My_IAPSNJ_Change_Password::MIN_LENGTH
                ),
                'audience' => __( 'Logged-in members. Visitors see a log-in link.', 'my-iapsnj' ),
                'page'     => __( 'Change My Password', 'my-iapsnj' ),
                'atts'     => [
                    [ 'button', __( 'Update Password', 'my-iapsnj' ), __( 'Text of the submit button.', 'my-iapsnj' ) ],
                ],
                'examples' => [ '[' . My_IAPSNJ_Change_Password::SHORTCODE . ']' ],
            ],
            'iapsnj_renew_link' => [
                'title'    => __( 'Renew link', 'my-iapsnj' ),
                'summary'  => __( 'A link (not a card) to the renewal checkout for the logged-in member: the renewal product for their type, set in Dues → Membership Products. Visitors get a link to the Join page instead; Lifetime and Honorary members get nothing. Handy in emails and the member area.', 'my-iapsnj' ),
                'audience' => __( 'Everyone: members get the renewal link, visitors the Join page link.', 'my-iapsnj' ),
                'page'     => __( 'Any page or email', 'my-iapsnj' ),
                'atts'     => [
                    [ 'text', __( 'Renew my membership', 'my-iapsnj' ), __( 'Link text for a member.', 'my-iapsnj' ) ],
                    [ 'join_text', __( 'Join IAPSNJ', 'my-iapsnj' ), __( 'Link text for a visitor (goes to the Join page URL in Dues → Membership Products).', 'my-iapsnj' ) ],
                    [ 'class', 'button my-iapsnj-renew-link', __( 'CSS classes on the link.', 'my-iapsnj' ) ],
                ],
                'examples' => [
                    '[iapsnj_renew_link]',
                    '[iapsnj_renew_link text="Renew now" class="button"]',
                ],
            ],
        ];
    }
}
