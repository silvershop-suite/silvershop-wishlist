<?php

declare(strict_types=1);

namespace SilverShop\Wishlist\Extension;

use SilverShop\Page\AccountPage;
use SilverShop\Page\Product;
use SilverShop\Page\ProductController;
use SilverShop\Wishlist\Control\WishlistController;
use SilverShop\Wishlist\Model\SessionWishlist;
use SilverShop\Wishlist\Model\Wishlist;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Exposes the wishlist state + toggle link on the product page (for the WishlistButton include).
 *
 * @extends Extension<ProductController>
 */
class WishlistProductControllerExtension extends Extension
{
    public function InWishlist(): bool
    {
        $product = $this->owner->data();
        if (!$product instanceof Product) {
            return false;
        }

        $member = Security::getCurrentUser();
        if ($member) {
            return $member->hasInWishlist($product);
        }

        return WishlistController::config()->get('allow_guest')
            ? SessionWishlist::create()->HasBuyable($product)
            : false;
    }

    /**
     * Whether the wishlist button should show at all (a member, or guests allowed).
     */
    public function WishlistEnabled(): bool
    {
        return Security::getCurrentUser() !== null
            || (bool) WishlistController::config()->get('allow_guest');
    }

    /**
     * Link to the member's wishlist in the account area (empty for guests — they have no wishlist page).
     */
    public function WishlistPageLink(): string
    {
        if (!Security::getCurrentUser()) {
            return '';
        }

        $account = AccountPage::get()->first();

        return $account ? (string) $account->Link('wishlist') : '';
    }

    public function WishlistLink(string $action = 'toggle'): string
    {
        return WishlistController::singleton()->Link($action);
    }

    /**
     * Root-relative wishlist endpoint link (e.g. "/wishlist/toggle"), safe as a form action / formaction
     * on the product page where the relative {@link WishlistLink} would resolve against the product URL.
     */
    public function WishlistToggleLink(string $action = 'toggle'): string
    {
        return Controller::join_links('/', WishlistController::singleton()->Link($action));
    }

    /**
     * Whether members may keep several named lists (mirrors the controller config).
     */
    public function AllowMultipleLists(): bool
    {
        return (bool) WishlistController::config()->get('allow_multiple_lists');
    }

    /**
     * Root-relative base for the wishlist endpoints (e.g. "/wishlist"), used by the popup's fetch() calls so
     * relative paths don't resolve against nested product URLs.
     */
    public function WishlistBaseLink(): string
    {
        return Controller::join_links('/', WishlistController::singleton()->Link());
    }

    /**
     * Whether a member is logged in — the multi-list "save to list" popup is member-only (guests get the plain
     * session toggle).
     */
    public function IsWishlistMember(): bool
    {
        return Security::getCurrentUser() !== null;
    }

    /**
     * Whether to render the built-in "save to list" popup on this product page: multiple lists enabled, the
     * popup not disabled by config, and a member logged in. Gate the template markup/script on this, so turning
     * off `enable_popup` (to ship your own UI) cleanly removes it.
     */
    public function WishlistPopupEnabled(): bool
    {
        return $this->AllowMultipleLists()
            && (bool) WishlistController::config()->get('enable_popup')
            && $this->IsWishlistMember();
    }

    /**
     * The logged-in member's lists (oldest first) for the "add to list" picker — only when multiple lists are
     * enabled and the member actually has more than one. Null otherwise (the plain toggle button is enough).
     *
     * @return DataList<Wishlist>|null
     */
    public function MemberWishlists(): ?DataList
    {
        if (!$this->AllowMultipleLists()) {
            return null;
        }

        $member = Security::getCurrentUser();
        if (!$member) {
            return null;
        }

        $lists = $member->OrderedWishlists();

        return $lists->count() > 1 ? $lists : null;
    }

    /**
     * Hidden CSRF field for the no-JS wishlist POST form.
     */
    public function WishlistSecurityField(): DBHTMLText
    {
        $token = SecurityToken::inst();
        $field = DBHTMLText::create();
        $field->setValue(sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            Convert::raw2att($token->getName()),
            Convert::raw2att($token->getValue())
        ));

        return $field;
    }
}
