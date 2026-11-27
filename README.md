# OrderGuard

A lightweight, single-file WordPress plugin that protects WooCommerce stores from duplicate and fake orders using temporary IP and device locking.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588A?style=flat-square&logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![HPOS](https://img.shields.io/badge/WooCommerce_HPOS-Compatible-10B981?style=flat-square)

[![Download Latest Version](https://img.shields.io/badge/Download-orderguard.zip-25D366?style=for-the-badge)](https://github.com/abirboss8055/orderguard/releases/latest/download/orderguard.zip)

---

## Overview

OrderGuard stops rapid repeat or fake orders without slowing down your store or adding custom database tables. When a customer places an order, OrderGuard temporarily locks their IP address and browser device token using WordPress Transients. The lock expires automatically after your chosen duration (default: 10 minutes), or unlocks immediately the moment an admin changes the order status away from **Processing**.

## Key Features

* **Universal Checkout Support:** Works out of the box with Classic WooCommerce Checkout, WooCommerce Block Checkout (Store API), and CartFlows funnels.
* **IP + Device Locking:** Tracks both client IP (including Cloudflare/proxy headers) and a secure HTTP-only device cookie.
* **Instant Admin Unlock:** Changing an order's status from `Processing` to any other status (such as `Completed`, `Cancelled`, or `On Hold`) immediately clears the customer's lock.
* **WhatsApp Emergency CTA:** Displays a clean lock overlay on checkout with your custom warning text and a direct clickable WhatsApp button.
* **Admin Settings Panel:** Configure lock duration, messages, WhatsApp number, pre-filled message, and admin bypass under **WooCommerce > OrderGuard**.
* **Zero Bloat:** Single PHP file, zero external dependencies, no custom database tables, and full WooCommerce HPOS compatibility.

## Installation

1. Download the latest ready-to-install **`orderguard.zip`** from the [Releases page](https://github.com/abirboss8055/orderguard/releases/latest) (or click the download button above).
2. In your WordPress dashboard, go to **Plugins > Add New Plugin > Upload Plugin**.
3. Select `orderguard.zip`, click **Install Now**, and then **Activate**.
4. Navigate to **WooCommerce > OrderGuard** to set your WhatsApp number, pre-filled message, and lock duration.

## How It Works

1. **Order Placed:** Customer completes checkout on Classic, Block, or CartFlows checkout.
2. **Lock Applied:** OrderGuard stores hashed IP and device transients for the configured duration and links them to the order metadata.
3. **Checkout Protection:** Any repeat checkout attempt while locked is blocked server-side, and the checkout page displays your custom notice with a direct WhatsApp support button.
4. **Automatic or Manual Unlock:** The lock clears automatically when the timer expires, or immediately when an admin updates the order status from `Processing` to another status.

## Repository Structure

```text
orderguard/
├── .github/
│   └── workflows/
│       └── release-zip.yml    # Automatically packages orderguard.zip on every GitHub Release
├── orderguard.php             # Complete single-file WordPress plugin
└── README.md                  # Plugin documentation