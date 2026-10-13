<?php

declare(strict_types=1);

namespace SilverShop\Wishlist\Extension;

use SilverShop\Page\Product;
use SilverShop\Wishlist\Control\WishlistController;
use SilverShop\Wishlist\Model\SessionWishlist;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Per-product wishlist accessors for catalogue tiles — the product listing, featured strips and related
 * products, where the {@link Product} is the record in template scope rather than the controller. Lets a
 * theme render the heart already filled for items on the wishlist and point a no-JS toggle form at the
 * endpoint. Guest- and member-aware, mirroring {@link WishlistProductControllerExtension} on the product
 * page (which stays the source for $Top.InWishlist in the buy box).
 *
 * @extends Extension<Product>
 */
class WishlistProductExtension extends Extension
{
    /**
     * Whether this product is already on the viewer's wishlist — the member's default list, or the guest
     * session list when allow_guest is on. Drives the filled heart state on a catalogue tile.
     */
    public function InWishlist(): bool
    {
        $product = $this->owner;
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
     * Root-relative link to the wishlist endpoint (e.g. "/wishlist/toggle"), safe to use as a form action
     * from a nested catalogue URL where a relative link would mis-resolve against the product path.
     */
    public function WishlistToggleLink(string $action = 'toggle'): string
    {
        return Controller::join_links('/', WishlistController::singleton()->Link($action));
    }

    /**
     * Hidden CSRF field for the no-JS wishlist POST form on a tile (same token the controller checks).
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
