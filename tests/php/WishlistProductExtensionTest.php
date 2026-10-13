<?php

declare(strict_types=1);

namespace SilverShop\Wishlist\Tests;

use SilverShop\Page\Product;
use SilverShop\Wishlist\Model\SessionWishlist;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The per-Product accessors used by catalogue tiles: InWishlist() must reflect the member's default list
 * and the guest session list, and WishlistToggleLink() must be a root-relative endpoint link.
 */
class WishlistProductExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'fixtures/wishlist.yml';

    private Controller $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // The guest session wishlist reads ShopTools::getSession(); provide a request + session.
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);
        $this->controller = new Controller();
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();
    }

    protected function tearDown(): void
    {
        $this->controller->popCurrent();
        parent::tearDown();
    }

    private function productA(): Product
    {
        return $this->objFromFixture(Product::class, 'product_a');
    }

    private function productB(): Product
    {
        return $this->objFromFixture(Product::class, 'product_b');
    }

    public function testToggleLinkIsRootRelative(): void
    {
        $link = $this->productA()->WishlistToggleLink();

        $this->assertStringStartsWith('/', $link);
        $this->assertStringEndsWith('wishlist/toggle', $link);
    }

    public function testInWishlistForMember(): void
    {
        $this->logInAs('buyer');

        $this->assertFalse($this->productA()->InWishlist());

        $this->objFromFixture(\SilverStripe\Security\Member::class, 'buyer')
            ->Wishlist()
            ->AddBuyable($this->productA());

        $this->assertTrue($this->productA()->InWishlist());
        $this->assertFalse($this->productB()->InWishlist());
    }

    public function testInWishlistForGuestSession(): void
    {
        $this->logOut();

        $this->assertFalse($this->productA()->InWishlist());

        SessionWishlist::create()->AddBuyable($this->productA());

        $this->assertTrue($this->productA()->InWishlist());
        $this->assertFalse($this->productB()->InWishlist());
    }

    public function testSecurityFieldRendersHiddenInput(): void
    {
        $html = (string) $this->productA()->WishlistSecurityField();

        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="SecurityID"', $html);
    }
}
