# SilverShop Wishlist

Member wishlists for [SilverShop](https://github.com/silvershop/silvershop-core) on Silverstripe CMS 6: logged-in
customers save products (or specific variations) for later, manage them in the account area, and move them to the
cart.

> **Status — v1 + v2.** Add / remove / toggle from the product page, an account section listing saved items
> with live price + availability and a "price dropped" badge, and move-to-cart (single or all). **Guests** get
> a session wishlist that is **merged into their account on login** (v2). The data model is built around a
> `Wishlist` container, so the remaining tiers (multiple named lists, sharing) slot in without reshaping it.

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6.0
- `silvershop/core` ^6
- `silvershop/stock` ^6 *(optional — shows live in-stock / out-of-stock state)*

## Installation

```bash
composer require silvershop-suite/wishlist
```

Then run `/dev/build?flush=all`.

## Usage

**Product page — the toggle button.** Add the include to your product template (the product is in scope):

```ss
<% include SilverShop\Wishlist\WishlistButton %>
```

It renders for members (and for guests when `allow_guest` is on) and posts to the wishlist controller
(CSRF-checked), toggling the whole product on/off the list and returning to the page. Once an item is saved,
members also get a small **View wishlist** link next to the button, pointing at their account wishlist.

**Account area — the wishlist section.** It's available at `…/account/wishlist` out of the box (rendered by
`AccountPage_wishlist.ss`). Add a link to it in your account navigation — the account controller exposes
`$WishlistLink`:

```ss
<a href="$WishlistLink"><%t SilverShop\Wishlist.Nav "Wishlist" %></a>
```

The section lists each saved item with its current price, availability, a price-drop badge, **Add to cart** /
**Remove**, and **Add all to cart**.

**Variations.** The model stores a specific variation per item (`WishlistItem.Variation`), used for price, stock
and move-to-cart. The default product-page button saves the whole product; a variation-aware save can post a
`VariationID` to the same endpoints.

## Endpoints

POST-only + CSRF-checked, operating on the current member's list (or the `WishlistID` given, when multiple lists
are on), then redirecting back — or returning JSON when called with `X-Requested-With: XMLHttpRequest` (the popup
uses this):

| Action | Method | Does |
| --- | --- | --- |
| `wishlist/add` | POST | Add `ProductID` (+ optional `VariationID`, `Quantity`, `WishlistID`) |
| `wishlist/remove` | POST | Remove the buyable (+ optional `WishlistID`) |
| `wishlist/toggle` | POST | Add if absent, remove if present |
| `wishlist/movetocart` | POST | Add the buyable to the cart |
| `wishlist/moveall` | POST | Add every item to the cart |
| `wishlist/createlist` | POST | Create a named list (`Title`); with a `ProductID`, also files it there *(multi-list)* |
| `wishlist/renamelist` | POST | Rename an owned list (`WishlistID`, `Title`) *(multi-list)* |
| `wishlist/deletelist` | POST | Delete an owned list (`WishlistID`) *(multi-list)* |
| `wishlist/lists` | GET | JSON of the member's lists + a `contains` flag for `ProductID`/`VariationID` *(multi-list)* |
| `wishlist/share` | POST | Make a list public (generates a token) `(WishlistID)` *(sharing)* |
| `wishlist/unshare` | POST | Make a list private again `(WishlistID)` *(sharing)* |
| `wishlist/shared/<token>` | GET | Public read-only view of a shared list — no login required *(sharing)* |

The JSON payload is `{ success, inAny, lists: [{ id, title, contains }] }`.

## Configuration

```yaml
SilverShop\Wishlist\Control\WishlistController:
  allow_guest: true              # guests build a session wishlist, merged on login (false = require login)
  remove_on_add_to_cart: false   # remove an item once it's moved to the cart
  allow_multiple_lists: false    # let members keep several named lists (create / rename / delete, pick a target)
  enable_popup: true             # render the built-in "save to list" popup JS (only applies with allow_multiple_lists)
  allow_sharing: false           # let members share a list as a public read-only link
```

**Sharing (`allow_sharing`).** Off by default. Turn it on and the account area gains a **Share** / **Stop sharing**
control per list: sharing generates a random, unguessable token and exposes the list read-only at
`/wishlist/shared/<token>` to anyone with the link (no login). The public page shows only the list title and its
products — never the owner's name or email — and is marked `noindex`. Stop-sharing makes the link 404 again. Override
the `SilverShop\Wishlist\WishlistShared` template to restyle the public page.

**Multiple named lists (`allow_multiple_lists`).** Off by default — every member has one list. Turn it on and the
account area gains create / rename / delete controls for named lists, and the move-to-cart / remove actions operate on
the list each item belongs to. On the product page, a logged-in member clicking the heart opens a **"save to list"
popup** — a checkbox per list (the default pre-checked), plus "+ new list" — so an item can live in several lists at
once. This popup is **progressive enhancement**: without JavaScript the heart just toggles the member's default
(oldest) list, and the account page is used to organise lists. Set `enable_popup: false` to drop the built-in popup
and drive the JSON endpoints yourself.

## Customising & extending

Everything the module renders or decides is overridable — no core edits needed.

**Templates** (override by copying the path into your theme — standard Silverstripe template precedence):

| Template | Purpose |
| --- | --- |
| `SilverShop\Wishlist\WishlistButton` | The product-page heart (+ data/markup for the popup) |
| `SilverShop\Wishlist\WishlistPopupScript` | *Only* the popup JavaScript — override to replace behaviour, leave an empty file to disable |
| `SilverShop\Wishlist\WishlistItems` | The account-area list(s) view |
| `SilverShop\Page\Layout\AccountPage_wishlist` | The account `wishlist` action wrapper |

**Config:** the flags above (`allow_guest`, `remove_on_add_to_cart`, `allow_multiple_lists`, `enable_popup`).

**PHP extension hooks** — add a `DataExtension` to the relevant class and implement:

| Hook | On | Fires when |
| --- | --- | --- |
| `onAddToWishlist($item, $buyable)` | `Wishlist` | A buyable is added to a list |
| `onRemoveFromWishlist($item, $buyable)` | `Wishlist` | A buyable is removed from a list |
| `onCreateWishlist($list)` / `onRenameWishlist($list)` / `onDeleteWishlist($list)` | `WishlistController` | A list is created / renamed / deleted |
| `onShareWishlist($list)` / `onUnshareWishlist($list)` | `WishlistController` | A list is shared / unshared |
| `updateListsPayload(&$payload, $member, $buyable)` | `WishlistController` | Building the popup's JSON (add/adjust fields) |
| `updateWishlistResponse($request)` | `WishlistController` | Just before a non-AJAX redirect |

**JavaScript events** (dispatched on the `.wishlist-button` element, they bubble — listen on `document` to integrate
without replacing the popup):

| Event | `event.detail` |
| --- | --- |
| `wishlist:opened` | `{ payload, justAdded }` |
| `wishlist:changed` | `{ action, inAny, lists, listId? }` — `action` is `add` / `remove` / `create` |

## Translations

All front-end strings go through `<%t SilverShop\Wishlist.* %>`. Ships with **en, nl, de, fr, es, it**
(`lang/*.yml`); override or add locales the usual Silverstripe way.

## Roadmap

Done: member wishlist (v1) + guest session wishlist merged on login (v2) + multiple named lists (v3,
`allow_multiple_lists`) + public read-only sharing (v4, `allow_sharing`).

Back-in-stock / price-drop *alerts* are out of scope here (a separate `silvershop-suite/stock-alerts` concern).

## Development

```bash
composer install
composer lint   # PHP_CodeSniffer (PSR-12)
composer stan   # PHPStan (level 5) + silverstan
composer test   # PHPUnit
```

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
