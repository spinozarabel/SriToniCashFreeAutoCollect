# SriToniCashFreeAutoCollect
Uses Cashfree autocollect feature for order-payment reconciliation in WC. Interfaces with Moodle and WP LTI. Custom developed for SriToni Learning Services
Order and Payment reconciliation:

We wrote a custom plugin called SriToniCashfreeAutoCollect to implement the following:

Settings for Cashfree API, other information such as permissible student categories, groups, etc.
Implement a BACS payment gateway that displays virtual account information and instructions for bank transfer. This data is extracted from user meta.
Webhook processing after any payment is collected
View special columns in the Orders page
View payments and reconciliation orders for any virtual account by clicking a custom column in the orders page using Cashfree API.
Reconcile payments and Orders on demand if webhook is not working.
Upon order completion, update the payments user profile field in Moodle with the new payment using Moodle REST API.
IMPORTANT: Before you activate this plugin ensure that WooCommerce is installed and activated, otherwise the site will fail.
IMPORTANT: Before you dectivate WooCommerce first deactivate this plugin otherwise the site will fail.
