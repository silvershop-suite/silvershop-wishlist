<?php

declare(strict_types=1);

namespace SilverShop\Wishlist\Control;

use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Wishlist\Model\SessionWishlist;
use SilverShop\Wishlist\Model\Wishlist;
use SilverShop\Wishlist\Model\WishlistHandler;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Front-end endpoints for a wishlist: add / remove / toggle a buyable, and move items to the cart. Every
 * action is POST-only and CSRF-checked (mirroring the hardened review-vote controller), then redirects back.
 * Logged-in members use their database list; guests use a session list (when allow_guest is on, the default)
 * that is merged into their account on login — otherwise guests are sent to the login screen.
 */
class WishlistController extends Controller
{
    use Configurable;

    private static string $url_segment = 'wishlist';

    /**
     * Remove an item from the wishlist once it has been moved to the cart.
     */
    private static bool $remove_on_add_to_cart = false;

    /**
     * Let guests build a session wishlist (merged into their account on login). When false, guests are sent
     * to the login screen instead.
     */
    private static bool $allow_guest = true;

    /**
     * Let members keep several named wishlists (create / rename / delete, and choose which one to add to).
     * When false (the default), every member has a single list and the list-management actions are disabled.
     */
    private static bool $allow_multiple_lists = false;

    /**
     * Render the built-in "save to list" popup JavaScript on the product page (only has an effect with
     * allow_multiple_lists). Turn it off to ship your own front-end against the JSON endpoints / DOM events.
     */
    private static bool $enable_popup = true;

    /**
     * Let members share a wishlist as a public, read-only link (anyone with the link can view it). Off by
     * default. When on, the account area gains share / stop-sharing controls.
     */
    private static bool $allow_sharing = false;

    /**
     * @var array<string>
     */
    private static array $allowed_actions = [
        'add',
        'remove',
        'toggle',
        'movetocart',
        'moveall',
        'createlist',
        'renamelist',
        'deletelist',
        'lists',
        'state',
        'share',
        'unshare',
        'shared',
    ];

    public function Link($action = null): string
    {
        return Controller::join_links(self::config()->get('url_segment'), $action);
    }

    public function add(HTTPRequest $request)
    {
        return $this->mutate($request, 'add');
    }

    public function remove(HTTPRequest $request)
    {
        return $this->mutate($request, 'remove');
    }

    public function toggle(HTTPRequest $request)
    {
        return $this->mutate($request, 'toggle');
    }

    public function movetocart(HTTPRequest $request)
    {
        return $this->mutate($request, 'movetocart');
    }

    public function moveall(HTTPRequest $request)
    {
        return $this->mutate($request, 'moveall');
    }

    public function createlist(HTTPRequest $request)
    {
        return $this->manageList($request, 'create');
    }

    public function renamelist(HTTPRequest $request)
    {
        return $this->manageList($request, 'rename');
    }

    public function deletelist(HTTPRequest $request)
    {
        return $this->manageList($request, 'delete');
    }

    /**
     * JSON: the current member's lists and whether each contains the given buyable — powers the product-page
     * "save to list" popup. Member-only, requires allow_multiple_lists.
     */
    public function lists(HTTPRequest $request)
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return $this->httpError(403);
        }
        if (!self::config()->get('allow_multiple_lists')) {
            return $this->httpError(400);
        }

        return $this->jsonResponse($this->listsPayload($member, $this->buyableFromRequest($request)));
    }

    /**
     * JSON: the product IDs on the current viewer's wishlist (the member's default list, or the guest
     * session list when allow_guest is on) plus the count. Read-only GET (no CSRF, no mutation). Lets a
     * cached / session-neutral catalogue tile hydrate its heart on load, and a header badge show the
     * count. Mirrors the InWishlist() heart semantics (member default list / session list).
     */
    public function state(HTTPRequest $request)
    {
        $ids = [];
        $member = Security::getCurrentUser();
        if ($member && $member->hasMethod('Wishlists')) {
            $list = $member->Wishlists()->sort('Created ASC, ID ASC')->first();
            if ($list) {
                $ids = $list->Items()->column('ProductID');
            }
        } elseif ((bool) self::config()->get('allow_guest')) {
            $session = SessionWishlist::create();
            if (method_exists($session, 'rows')) {
                foreach ($session->rows() as $row) {
                    if (!empty($row['ProductID'])) {
                        $ids[] = (int) $row['ProductID'];
                    }
                }
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $this->jsonResponse(['success' => true, 'ids' => $ids, 'count' => count($ids)]);
    }

    public function share(HTTPRequest $request)
    {
        return $this->setSharing($request, true);
    }

    public function unshare(HTTPRequest $request)
    {
        return $this->setSharing($request, false);
    }

    /**
     * Public, read-only view of a shared wishlist, found by its token. No login required.
     */
    public function shared(HTTPRequest $request)
    {
        $token = (string) $request->param('ID');
        if ($token === '') {
            return $this->httpError(404);
        }

        $list = Wishlist::get()->filter(['Token' => $token, 'Visibility' => 'Shared'])->first();
        if (!$list) {
            return $this->httpError(404);
        }

        return $this->customise(['SharedWishlist' => $list])
            ->renderWith(['SilverShop\Wishlist\WishlistShared']);
    }

    /**
     * Toggle a member's list between shared (public read-only link) and private. POST + CSRF, owner-only,
     * and only when allow_sharing is on.
     */
    protected function setSharing(HTTPRequest $request, bool $share)
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }
        if (!self::config()->get('allow_sharing')) {
            return $this->httpError(400);
        }

        $member = Security::getCurrentUser();
        if (!$member) {
            $backURL = urlencode((string) $request->getHeader('Referer'));

            return $this->redirect(Controller::join_links(Security::login_url(), '?BackURL=' . $backURL));
        }

        $id = (int) $request->postVar('WishlistID');
        $list = $id ? $member->WishlistByID($id) : $member->Wishlist();
        if (!$list) {
            return $this->httpError(404);
        }

        $list->Visibility = $share ? 'Shared' : 'Private';
        if ($share) {
            $list->ensureToken();
        }
        $list->write();
        $this->extend($share ? 'onShareWishlist' : 'onUnshareWishlist', $list);

        return $this->back($request);
    }

    protected function mutate(HTTPRequest $request, string $op)
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }

        $handler = $this->currentHandler($request);
        if (!$handler) {
            $backURL = urlencode((string) $request->getHeader('Referer'));

            return $this->redirect(Controller::join_links(Security::login_url(), '?BackURL=' . $backURL));
        }

        if ($op === 'moveall') {
            $handler->MoveAllToCart((bool) self::config()->get('remove_on_add_to_cart'));

            return $this->back($request);
        }

        $buyable = $this->buyableFromRequest($request);
        if (!$buyable) {
            return $this->httpError(404);
        }

        switch ($op) {
            case 'add':
                $handler->AddBuyable($buyable, $this->requestedQuantity($request));
                break;
            case 'remove':
                $handler->RemoveBuyable($buyable);
                break;
            case 'toggle':
                $handler->ToggleBuyable($buyable, $this->requestedQuantity($request));
                break;
            case 'movetocart':
                ShoppingCart::singleton()->add($buyable, $this->requestedQuantity($request));
                if (self::config()->get('remove_on_add_to_cart')) {
                    $handler->RemoveBuyable($buyable);
                }
                break;
        }

        if ($this->isAjax($request)) {
            $member = Security::getCurrentUser();

            return $this->jsonResponse(
                $member
                    ? $this->listsPayload($member, $buyable)
                    : ['success' => true, 'lists' => [], 'inAny' => false]
            );
        }

        return $this->back($request);
    }

    /**
     * The wishlist to operate on: the member's database list (a specific owned list when allow_multiple_lists
     * and a valid WishlistID is posted, otherwise their default list), or a guest session list (when
     * allow_guest), or null — meaning the guest must log in first.
     */
    protected function currentHandler(HTTPRequest $request): ?WishlistHandler
    {
        $member = Security::getCurrentUser();
        if ($member) {
            if (self::config()->get('allow_multiple_lists')) {
                $list = $member->WishlistByID((int) $request->postVar('WishlistID'));
                if ($list) {
                    return $list;
                }
            }

            return $member->Wishlist();
        }

        if (self::config()->get('allow_guest')) {
            return SessionWishlist::create();
        }

        return null;
    }

    /**
     * Create / rename / delete one of the member's named lists. Member-only, POST + CSRF, and only when
     * allow_multiple_lists is on.
     */
    protected function manageList(HTTPRequest $request, string $op)
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }

        if (!self::config()->get('allow_multiple_lists')) {
            return $this->httpError(400);
        }

        $member = Security::getCurrentUser();
        if (!$member) {
            $backURL = urlencode((string) $request->getHeader('Referer'));

            return $this->redirect(Controller::join_links(Security::login_url(), '?BackURL=' . $backURL));
        }

        $title = trim((string) $request->postVar('Title'));

        if ($op === 'create') {
            $list = Wishlist::create();
            $list->MemberID = $member->ID;
            if ($title !== '') {
                $list->Title = $title;
            }
            $list->write();
            $this->extend('onCreateWishlist', $list);

            // From the product-page popup, "+ new list" also files the current item into the new list.
            $buyable = $this->buyableFromRequest($request);
            if ($buyable) {
                $list->AddBuyable($buyable, $this->requestedQuantity($request));
            }

            if ($this->isAjax($request)) {
                return $this->jsonResponse($this->listsPayload($member, $buyable));
            }

            return $this->back($request);
        }

        $list = $member->WishlistByID((int) $request->postVar('WishlistID'));
        if (!$list) {
            return $this->httpError(404);
        }

        if ($op === 'rename' && $title !== '') {
            $list->Title = $title;
            $list->write();
            $this->extend('onRenameWishlist', $list);
        } elseif ($op === 'delete') {
            $this->extend('onDeleteWishlist', $list);
            $list->delete();
        }

        return $this->back($request);
    }

    protected function buyableFromRequest(HTTPRequest $request): ?Buyable
    {
        // requestVar (not postVar) so the GET "lists" endpoint can resolve the buyable too.
        $variationID = (int) $request->requestVar('VariationID');
        if ($variationID) {
            return Variation::get()->byID($variationID);
        }

        $productID = (int) $request->requestVar('ProductID');
        if ($productID) {
            return Product::get()->byID($productID);
        }

        return null;
    }

    protected function requestedQuantity(HTTPRequest $request): int
    {
        return max(1, (int) $request->requestVar('Quantity'));
    }

    /**
     * Whether to answer with JSON (the product-page popup calls these endpoints via fetch).
     */
    protected function isAjax(HTTPRequest $request): bool
    {
        return $request->isAjax()
            || str_contains((string) $request->getHeader('Accept'), 'application/json');
    }

    /**
     * The member's lists with a "contains this buyable" flag — the popup's data model.
     *
     * @return array{success: bool, lists: array<int, array{id: int, title: string, contains: bool}>, inAny: bool}
     */
    protected function listsPayload(Member $member, ?Buyable $buyable): array
    {
        $lists = [];
        $inAny = false;
        foreach ($member->OrderedWishlists() as $list) {
            $contains = $buyable ? $list->HasBuyable($buyable) : false;
            $inAny = $inAny || $contains;
            $lists[] = [
                'id' => (int) $list->ID,
                'title' => (string) $list->Title,
                'contains' => $contains,
            ];
        }

        $payload = ['success' => true, 'lists' => $lists, 'inAny' => $inAny];
        // Let integrators add/adjust fields on the popup payload (e.g. list visibility, cover images).
        $this->extend('updateListsPayload', $payload, $member, $buyable);

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function jsonResponse(array $data): HTTPResponse
    {
        $response = HTTPResponse::create((string) json_encode($data));
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }

    protected function back(HTTPRequest $request)
    {
        $this->extend('updateWishlistResponse', $request);

        return $this->redirectBack();
    }
}
